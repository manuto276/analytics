#!/usr/bin/env bash
# Copies a package and its .sha256 into <deploy root>/packages/ on a server and
# runs `./console deploy <package>` there.
#
# Usage: deploy/manual/publish.sh user@host:/path/to/deploy-root [dist/analytics-<TS>.tar.gz] [-- deploy options]
#   Without a package, the newest dist/analytics-*.tar.gz is used.
#   Options after "--" are passed to `./console deploy` (e.g. -- --backup).
set -euo pipefail

usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
}

if [ "$#" -lt 1 ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
  usage
  [ "$#" -ge 1 ] && exit 0
  exit 2
fi

destination="$1"
shift
case "$destination" in
  *:/*) ;;
  *) echo "ERROR: destination must look like user@host:/absolute/path" >&2; exit 2 ;;
esac
remote_host="${destination%%:*}"
remote_path="${destination#*:}"

package=""
if [ "$#" -gt 0 ] && [ "$1" != "--" ]; then
  package="$1"
  shift
fi
if [ "$#" -gt 0 ] && [ "$1" = "--" ]; then
  shift
fi
deploy_args=("$@")

if [ -z "$package" ]; then
  repo_root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
  package="$(find "${repo_root}/dist" -maxdepth 1 -name 'analytics-*.tar.gz' 2>/dev/null | LC_ALL=C sort | tail -n 1)"
  if [ -z "$package" ]; then
    echo "ERROR: no package given and none found in dist/" >&2
    exit 1
  fi
fi

name="$(basename "$package")"
if ! [[ "$name" =~ ^analytics-[0-9]{8}T[0-9]{6}Z\.tar\.gz$ ]]; then
  echo "ERROR: invalid package name: ${name}" >&2
  exit 2
fi
for f in "$package" "${package}.sha256"; do
  [ -f "$f" ] || { echo "ERROR: ${f} not found" >&2; exit 1; }
done

quote() {
  printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

echo "==> Uploading ${name} to ${remote_host}:${remote_path}/packages/"
scp -p "$package" "${package}.sha256" "${remote_host}:$(quote "${remote_path}/packages/")"

remote_cmd="cd $(quote "$remote_path") && ./console deploy $(quote "$name")"
for arg in ${deploy_args[@]+"${deploy_args[@]}"}; do
  remote_cmd+=" $(quote "$arg")"
done

echo "==> Deploying on ${remote_host}: ${remote_cmd}"
# shellcheck disable=SC2029 # remote_cmd is expanded locally on purpose (quoted above)
ssh "$remote_host" "$remote_cmd"
