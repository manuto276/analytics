#!/usr/bin/env bash
# Deploy smoke test on a container imitating a managed PHP host
# (non-root PHP-FPM 8.4, nginx with $realpath_root, MySQL 8.4).
#
# Usage: deploy/manual/smoke/smoke.sh <analytics-TS.tar.gz> [second-package.tar.gz] [options]
#   --env-file FILE   extra lines appended to shared/.env (app secrets etc.)
#   --keep            leave the stack running afterwards (default: tear down)
#   --no-backup       do not run the second deploy with --backup
# Without a second package, the first one is re-packed with a newer timestamp (repack.sh).
# Environment: SMOKE_HTTP_PORT (default 8089).
#
# Flow: init -> deploy #1 -> health -> deploy #2 (+backup) -> health -> rollback
#       -> health -> list/status -> cleanup.
set -euo pipefail

usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
}

here="$(cd "$(dirname "$0")" && pwd)"
manual_dir="$(dirname "$here")"
deploy_root=/home/site/htdocs/stats.example.test
port="${SMOKE_HTTP_PORT:-8089}"
export SMOKE_HTTP_PORT="$port"

pkg1=""
pkg2=""
env_file=""
keep=0
backup=1
while [ "$#" -gt 0 ]; do
  case "$1" in
    --env-file) shift; env_file="${1:?--env-file needs a file}" ;;
    --keep) keep=1 ;;
    --no-backup) backup=0 ;;
    -h|--help) usage; exit 0 ;;
    -*) echo "ERROR: unknown option: $1" >&2; usage >&2; exit 2 ;;
    *)
      if [ -z "$pkg1" ]; then pkg1="$1"
      elif [ -z "$pkg2" ]; then pkg2="$1"
      else echo "ERROR: unexpected argument: $1" >&2; exit 2
      fi
      ;;
  esac
  shift
done
[ -n "$pkg1" ] || { usage >&2; exit 2; }
for f in "$pkg1" "${pkg1}.sha256" ${pkg2:+"$pkg2" "${pkg2}.sha256"} ${env_file:+"$env_file"}; do
  [ -f "$f" ] || { echo "ERROR: ${f} not found" >&2; exit 1; }
done

work="$(mktemp -d "${TMPDIR:-/tmp}/analytics-smoke.XXXXXX")"

dc() {
  docker compose -f "$here/compose.yml" "$@"
}

cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then
    echo "---- smoke test FAILED (exit ${status}); recent container logs ----" >&2
    dc logs --tail=60 php web >&2 || true
  fi
  if [ "$keep" -eq 1 ]; then
    echo "Stack left running (--keep): docker compose -f ${here}/compose.yml down -v"
  else
    dc down -v --remove-orphans >/dev/null 2>&1 || true
  fi
  rm -rf "$work"
  exit "$status"
}
trap cleanup EXIT

step() {
  printf '\n==== %s\n' "$*"
}

fail() {
  echo "SMOKE FAILURE: $*" >&2
  exit 1
}

# Runs ./console inside the php container as the site user.
console() {
  dc exec -T -u site php ./console "$@"
}

as_root() {
  dc exec -T -u root php "$@"
}

package_ts() {
  basename "$1" | sed -E 's/^analytics-([0-9]{8}T[0-9]{6}Z)\.tar\.gz$/\1/'
}

ts_plus_one_second() {
  local iso
  iso="$(printf '%s' "$1" | sed -E 's/^(....)(..)(..)T(..)(..)(..)Z$/\1-\2-\3 \4:\5:\6 UTC/')"
  date -u -d "${iso} + 1 second" +%Y%m%dT%H%M%SZ 2>/dev/null \
    || date -u -j -v+1S -f '%Y%m%dT%H%M%SZ' "$1" +%Y%m%dT%H%M%SZ
}

package_commit() {
  tar -xzOf "$1" ./REVISION 2>/dev/null || tar -xzOf "$1" REVISION
}

upload_package() {
  local pkg="$1" name
  name="$(basename "$pkg")"
  dc cp "$pkg" "php:${deploy_root}/packages/${name}"
  dc cp "${pkg}.sha256" "php:${deploy_root}/packages/${name}.sha256"
  as_root chown site:site "${deploy_root}/packages/${name}" "${deploy_root}/packages/${name}.sha256"
  as_root chmod 0640 "${deploy_root}/packages/${name}" "${deploy_root}/packages/${name}.sha256"
}

# Asserts the public health endpoint (through nginx) reports the given version and commit.
expect_health() {
  local version="$1" commit="$2" body="" i
  for i in $(seq 1 30); do
    body="$(curl -fsS "http://127.0.0.1:${port}/api/v1/health" 2>/dev/null || true)"
    if printf '%s' "$body" | grep -Eq "\"version\"[[:space:]]*:[[:space:]]*\"${version}\"" \
      && printf '%s' "$body" | grep -Eq "\"commit\"[[:space:]]*:[[:space:]]*\"${commit}\""; then
      echo "health ok: version ${version}, commit ${commit} (after ${i} attempt(s))"
      return 0
    fi
    sleep 1
  done
  fail "health endpoint did not report version ${version} / commit ${commit}; last body: ${body}"
}

expect_current() {
  local target
  target="$(dc exec -T -u site php readlink current)"
  [ "$target" = "releases/$1" ] || fail "current -> ${target}, expected releases/$1"
  echo "current -> ${target}"
}

step "Preparing packages"
ts1="$(package_ts "$pkg1")"
commit1="$(package_commit "$pkg1" | tr -d '[:space:]')"
if [ -z "$pkg2" ]; then
  # A timestamp one second after the first package, so it is always newer.
  ts2="$(ts_plus_one_second "$ts1")"
  pkg2="$("$here/repack.sh" "$pkg1" "$ts2" --output "$work")"
fi
ts2="$(package_ts "$pkg2")"
commit2="$(package_commit "$pkg2" | tr -d '[:space:]')"
[ "$ts1" \< "$ts2" ] || fail "second package (${ts2}) must be newer than the first (${ts1})"
echo "package 1: ${ts1} ${commit1}"
echo "package 2: ${ts2} ${commit2}"

step "Starting the managed-host stack"
dc down -v --remove-orphans >/dev/null 2>&1 || true
dc up -d --build --wait

step "Installing the deploy console"
as_root chown site:site "$deploy_root"
as_root chmod 0750 "$deploy_root"
dc cp "$manual_dir/console" "php:${deploy_root}/console"
as_root chown site:site "${deploy_root}/console"
console --version

step "console init"
console init
console init >/dev/null
upload_package "$pkg1"

{
  echo "; written by smoke.sh"
  echo 'php_binary = "/usr/local/bin/php"'
  echo 'keep_releases = 5'
  echo 'keep_packages = 3'
  echo 'health_url = "http://web/api/v1/health"'
  echo 'health_tries = 15'
  echo 'health_interval = 2'
  echo 'health_timeout = 5'
  echo 'auto_rollback = true'
  echo 'backup_before_migrate = false'
  echo 'mysqldump_binary = "mysqldump"'
  echo 'shared[] = ".env"'
  echo 'shared[] = "var/log"'
  echo 'shared[] = "var/storage"'
} > "$work/deploy.ini"

{
  tar -xzOf "$pkg1" ./.env.example 2>/dev/null || tar -xzOf "$pkg1" .env.example 2>/dev/null || true
  echo
  echo "# ---- smoke overrides"
  echo "APP_ENV=prod"
  echo "APP_DEBUG=0"
  echo "APP_URL=http://127.0.0.1:${port}"
  echo "DATABASE_URL=mysql://analytics:analytics@mysql:3306/analytics?serverVersion=8.4"
  echo "DB_HOST=mysql"
  echo "DB_PORT=3306"
  echo "DB_NAME=analytics"
  echo "DB_USER=analytics"
  echo "DB_PASSWORD=analytics"
  if [ -n "$env_file" ]; then
    cat "$env_file"
  fi
} > "$work/env"

dc cp "$work/deploy.ini" "php:${deploy_root}/deploy.ini"
dc cp "$work/env" "php:${deploy_root}/shared/.env"
as_root chown site:site "${deploy_root}/deploy.ini" "${deploy_root}/shared/.env"
as_root chmod 0640 "${deploy_root}/deploy.ini"
as_root chmod 0600 "${deploy_root}/shared/.env"

step "console verify + deploy #1 (${ts1})"
console verify "analytics-${ts1}.tar.gz"
console deploy --dry-run
console deploy "analytics-${ts1}.tar.gz"
expect_current "$ts1"
expect_health "$ts1" "$commit1"
curl -fsS -o /dev/null -w 'GET / -> %{http_code}\n' "http://127.0.0.1:${port}/"
curl -fsS -o /dev/null -w 'GET /overview (SPA fallback) -> %{http_code}\n' "http://127.0.0.1:${port}/overview"

step "Checking layout and permissions"
# shellcheck disable=SC2016 # expanded by the container shell
dc exec -T -u site php sh -euc '
  test "$(readlink "releases/$1/.env")" = "../../shared/.env"
  test "$(readlink "releases/$1/var/log")" = "../../../shared/var/log"
  test "$(stat -c %a shared/.env)" = "600"
  test "$(stat -c %a console)" = "750"
  test "$(stat -c %a "releases/$1")" = "750"
  echo "shared links and permissions ok"
' sh "$ts1"

step "console deploy #2 (${ts2})"
upload_package "$pkg2"
if [ "$backup" -eq 1 ]; then
  console deploy "analytics-${ts2}.tar.gz" --backup
  dc exec -T -u site php sh -c "ls -l shared/var/storage/backups/${ts2}.sql.gz"
else
  console deploy "analytics-${ts2}.tar.gz"
fi
expect_current "$ts2"
expect_health "$ts2" "$commit2"

step "console app passthrough"
console app list >/dev/null
echo "app list ok"

step "console rollback"
if ! console rollback; then
  echo "rollback refused (probably migrations unknown to ${ts1}); retrying with --force"
  console rollback --force
fi
expect_current "$ts1"
expect_health "$ts1" "$commit1"

step "console list / status"
console list
console status

step "console cleanup"
console cleanup --keep=1 --packages
remaining="$(dc exec -T -u site php sh -c 'ls releases' | tr '\n' ' ')"
case "$remaining" in
  *"$ts1"*"$ts2"*) echo "releases kept: ${remaining}" ;;
  *) fail "cleanup removed current or previous release (left: ${remaining})" ;;
esac

step "Lock and history"
dc exec -T -u site php sh -c 'tail -n 5 .deploy/history.jsonl'

printf '\nSMOKE TEST PASSED\n'
