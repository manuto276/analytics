#!/usr/bin/env bash
# Production image check (plan §13.2 "Docker image"):
# builds php-runtime and nginx-runtime, starts compose.prod.yml with the bundled
# database, waits for /api/v1/health and posts one tracking payload.
#
# Usage: deploy/docker/scripts/test-image.sh [--keep]
# Environment: ANALYTICS_IMAGE_PORT (default 8099), ANALYTICS_VERSION (default local).
set -euo pipefail

repo_root="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$repo_root"

keep=0
[ "${1:-}" = "--keep" ] && keep=1

project="${IMAGE_PROJECT:-analytics-image-test}"
port="${ANALYTICS_IMAGE_PORT:-8099}"
version="${ANALYTICS_VERSION:-local}"
work="$(mktemp -d "${TMPDIR:-/tmp}/analytics-image.XXXXXX")"

env_file="$work/.env"
cat > "$env_file" <<EOF
APP_ENV=prod
APP_URL=http://127.0.0.1:${port}
LOG_LEVEL=warning
APP_SECRET=$(head -c 32 /dev/urandom | base64)
APP_ENCRYPTION_KEYS=k1:$(head -c 32 /dev/urandom | base64)
DATABASE_URL=mysql://analytics:analytics@mysql:3306/analytics?serverVersion=8.4
DB_PARTITIONING=true
INGEST_MODE=sync
TRUSTED_PROXIES=0.0.0.0/0
MYSQL_ROOT_PASSWORD=root
MYSQL_DATABASE=analytics
MYSQL_USER=analytics
MYSQL_PASSWORD=analytics
EOF

dc() {
  ANALYTICS_VERSION="$version" \
  ANALYTICS_ENV_FILE="$env_file" \
  ANALYTICS_HTTP_BIND="127.0.0.1:${port}" \
  MYSQL_ROOT_PASSWORD=root \
  MYSQL_PASSWORD=analytics \
  docker compose -p "$project" -f deploy/docker/compose.prod.yml --profile bundled-db "$@"
}

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "---- image test FAILED (exit ${status}); recent logs ----" >&2
    dc logs --tail=60 app web migrate >&2 || true
  fi
  if [ "$keep" -eq 1 ]; then
    echo "Stack left running (--keep): docker compose -p ${project} -f deploy/docker/compose.prod.yml down -v"
  else
    dc down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -rf "$work"
  exit "$status"
}
trap cleanup EXIT

echo "==> Building the production images"
build_args=(
  --build-arg "BUILD_TS=$(date -u +%Y%m%dT%H%M%SZ)"
  --build-arg "COMMIT=$(git rev-parse HEAD)"
  --build-arg "COMMIT_DATE=$(git show -s --format=%cI HEAD)"
  --build-arg "COMMIT_TIME=$(git show -s --format=%ct HEAD)"
)
docker buildx build -f deploy/docker/Dockerfile --target php-runtime "${build_args[@]}" \
  -t "ghcr.io/manuto276/analytics-php:${version}" --load .
docker buildx build -f deploy/docker/Dockerfile --target nginx-runtime "${build_args[@]}" \
  -t "ghcr.io/manuto276/analytics-web:${version}" --load .

echo "==> Starting the stack (bundled database)"
dc up -d --wait --wait-timeout 300 mysql migrate app web

echo "==> Health"
health=""
for _ in $(seq 1 30); do
  health="$(curl -fsS "http://127.0.0.1:${port}/api/v1/health" 2>/dev/null || true)"
  case "$health" in
    *'"status"'*) break ;;
  esac
  sleep 2
done
case "$health" in
  *'"status"'*) echo "health: ${health}" ;;
  *) echo "ERROR: /api/v1/health did not answer with a status: ${health}" >&2; exit 1 ;;
esac

echo "==> Static dashboard"
curl -fsS -o /dev/null -w 'GET / -> %{http_code}\n' "http://127.0.0.1:${port}/"

echo "==> Collect endpoint answers (unknown key must be refused, not crash)"
code="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: text/plain' \
  -H 'Origin: https://www.example.com' \
  --data '{"v":1,"k":"pk_000000000000000000000","l":"b","cv":0,"sw":1440,"e":[]}' \
  "http://127.0.0.1:${port}/t/e")"
echo "POST /t/e -> ${code}"
case "$code" in
  202|400|403|404|422) ;;
  *) echo "ERROR: unexpected status ${code} from /t/e" >&2; exit 1 ;;
esac

echo
echo "IMAGE TEST PASSED"
