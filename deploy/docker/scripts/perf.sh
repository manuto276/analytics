#!/usr/bin/env bash
# Load baseline (plan §13.2): seeds a site with `dev:seed`, then runs the k6
# script in services/e2e/perf against the compose test stack.
#
# Usage: deploy/docker/scripts/perf.sh
#
# Environment:
#   SEED_EVENTS     target number of seeded events (default 200000, a few
#                   minutes locally). The plan's baseline is 5000000:
#                   `SEED_EVENTS=5000000 make perf` — that seeding run takes
#                   hours, so it belongs on the nightly machine.
#   PERF_DAYS       days of history to spread the events over (default 30)
#   PERF_SKIP_SEED  1 to reuse whatever the perf site already holds
#   COLLECT_RPS     target collect rate (default 100)
#   REPORTS_RPS     target report rate (default 5)
#   DURATION        steady-state duration (default 60s), RAMP (default 20s)
#   TEST_PROJECT    compose project name (default analytics-test)
#
# The stack is started closer to a real deployment than the test defaults:
# the production php.ini (compose.perf.yml), Redis for the cache, rate limits
# and locks (APP_ENV=test otherwise uses a per-request array cache, so every
# request would re-read the site configuration from MySQL) and
# `LOG_LEVEL=warning` (the debug log writes one line per request into a
# bind-mounted file). Override with PERF_REDIS_DSN / PERF_LOG_LEVEL.
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$repo_root"

project="${TEST_PROJECT:-analytics-test}"
site_name="${PERF_SITE_NAME:-perf}"
days="${PERF_DAYS:-30}"
seed_events="${SEED_EVENTS:-200000}"
admin_email="${E2E_ADMIN_EMAIL:-admin@analytics.test}"
admin_password="${E2E_ADMIN_PASSWORD:-Fixture-Passw0rd-2026}"
k6_image="${K6_IMAGE:-grafana/k6:latest}"

# Setup phase: the ordinary test stack (dev:seed refuses APP_ENV=prod).
dc() {
  docker compose -p "$project" \
    -f deploy/docker/compose.base.yml \
    -f deploy/docker/compose.test.yml "$@"
}

# Measurement phase: the same containers with the production configuration.
dcp() {
  docker compose -p "$project" \
    -f deploy/docker/compose.base.yml \
    -f deploy/docker/compose.test.yml \
    -f deploy/docker/compose.perf.yml "$@"
}

# Waits until PHP-FPM answers through nginx. A 503 counts: the health endpoint
# reports "fail" while migrations are still pending, which is exactly the state
# this script fixes next.
wait_for_health() {
  for _ in $(seq 1 60); do
    # 200 or 503 both mean PHP-FPM answered; 502 means it did not.
    if "$@" exec -T nginx sh -c \
      'wget -q -S -O /dev/null http://127.0.0.1/api/v1/health 2>&1 | grep -qE "HTTP/1.1 (200|503)"'; then
      return 0
    fi
    sleep 1
  done
  echo "ERROR: the application did not answer on /api/v1/health" >&2
  return 1
}

echo "==> Test stack (redis cache, warning log level)"
export REDIS_DSN="${PERF_REDIS_DSN:-redis://redis:6379/0}"
export LOG_LEVEL="${PERF_LOG_LEVEL:-warning}"
DOCKER_UID="${DOCKER_UID:-$(id -u)}" DOCKER_GID="${DOCKER_GID:-$(id -g)}" \
  dc --profile redis up -d --build mysql redis php nginx
# The php container is recreated whenever this script changes its environment,
# and nginx resolves the upstream only at start-up: restart it so it picks the
# new address up instead of answering 502.
dc restart nginx >/dev/null
wait_for_health dc

dc exec -T php php bin/analytics migrations:migrate --no-interaction --allow-no-migration

echo "==> Admin user"
users="$(dc exec -T php php bin/analytics user:list)"
if ! printf '%s\n' "$users" | grep -qF "$admin_email"; then
  dc exec -T php sh -c "printf '%s' '${admin_password}' | php bin/analytics user:create-admin --email='${admin_email}' --password-stdin"
fi

echo "==> Perf site"
sites="$(dc exec -T php php bin/analytics site:list)"
line="$(printf '%s\n' "$sites" | grep -E "\| +${site_name} +\|" || true)"
if [ -z "$line" ]; then
  dc exec -T php php bin/analytics site:create \
    --name="$site_name" --domain='*.site.test' --timezone=UTC >/dev/null
  sites="$(dc exec -T php php bin/analytics site:list)"
  line="$(printf '%s\n' "$sites" | grep -E "\| +${site_name} +\|")"
fi
site_id="$(printf '%s\n' "$line" | sed -E 's/^\| *([0-9]+).*/\1/')"
public_key="$(printf '%s\n' "$line" | grep -oE 'pk_[A-Za-z0-9]{21}')"
[ -n "$site_id" ] && [ -n "$public_key" ] || { echo "ERROR: could not resolve the perf site" >&2; exit 1; }
echo "site ${site_id} (${public_key})"

if [ "${PERF_SKIP_SEED:-0}" != "1" ]; then
  # The seeder writes roughly four events per visit (pageviews, engagement and
  # the occasional custom event), so the visits-per-day option is derived from
  # the requested event count.
  visits_per_day="$(awk -v events="$seed_events" -v days="$days" 'BEGIN { v = int(events / (days * 4)); if (v < 1) v = 1; print v }')"
  echo "==> Seeding ~${seed_events} events (${days} days × ${visits_per_day} visits/day) — this is the slow part"
  time dc exec -T php php bin/analytics dev:seed \
    --site="$site_id" --days="$days" --visits="$visits_per_day" --seed=20260917
fi

echo "==> Switching the application to the production configuration"
DOCKER_UID="${DOCKER_UID:-$(id -u)}" DOCKER_GID="${DOCKER_GID:-$(id -g)}" \
  dcp up -d --force-recreate --no-deps php
dcp exec -T php php bin/analytics cache:warmup >/dev/null
dcp restart nginx >/dev/null
wait_for_health dcp

echo "==> Running k6"
docker run --rm -i \
  --network "${project}_default" \
  -v "${repo_root}/services/e2e/perf:/perf" \
  -e "BASE_URL=${BASE_URL:-http://analytics.test}" \
  -e "ANALYTICS_PUBLIC_KEY=${public_key}" \
  -e "ANALYTICS_SITE_ID=${site_id}" \
  -e "ADMIN_EMAIL=${admin_email}" \
  -e "ADMIN_PASSWORD=${admin_password}" \
  -e "COLLECT_RPS=${COLLECT_RPS:-100}" \
  -e "REPORTS_RPS=${REPORTS_RPS:-5}" \
  -e "DURATION=${DURATION:-60s}" \
  -e "RAMP=${RAMP:-20s}" \
  "$k6_image" run /perf/collect.js
