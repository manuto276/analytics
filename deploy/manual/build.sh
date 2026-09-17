#!/usr/bin/env bash
# Builds a timestamp-versioned release package:
#   dist/analytics-<TS>.tar.gz + dist/analytics-<TS>.tar.gz.sha256 (sha256sum format)
# Needs git and docker buildx only (no PHP or Node on the host).
#
# Usage: deploy/manual/build.sh [--allow-dirty] [--output DIR]
set -euo pipefail

usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
}

allow_dirty=0
output=dist
while [ "$#" -gt 0 ]; do
  case "$1" in
    --allow-dirty) allow_dirty=1 ;;
    --output) shift; output="${1:?--output needs a directory}" ;;
    --output=*) output="${1#--output=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "ERROR: unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

repo_root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$repo_root"

if [ "$allow_dirty" -ne 1 ] && [ -n "$(git status --porcelain --untracked-files=normal)" ]; then
  echo "ERROR: the working tree has uncommitted changes; commit them or pass --allow-dirty" >&2
  git status --short >&2
  exit 1
fi

command -v docker >/dev/null 2>&1 || { echo "ERROR: docker is required" >&2; exit 1; }
docker buildx version >/dev/null 2>&1 || { echo "ERROR: docker buildx is required" >&2; exit 1; }

TS="$(date -u +%Y%m%dT%H%M%SZ)"
COMMIT="$(git rev-parse HEAD)"
COMMIT_DATE="$(git show -s --format=%cI HEAD)"
COMMIT_TIME="$(git show -s --format=%ct HEAD)"
package="analytics-${TS}.tar.gz"

dirty_note=""
if [ "$allow_dirty" -eq 1 ] && [ -n "$(git status --porcelain)" ]; then
  dirty_note=" (DIRTY working tree: the package does not match the commit exactly)"
fi
echo "==> Building ${package} from commit ${COMMIT}${dirty_note}"
mkdir -p "$output"
docker buildx build \
  -f deploy/docker/Dockerfile \
  --target package \
  --build-arg "BUILD_TS=${TS}" \
  --build-arg "COMMIT=${COMMIT}" \
  --build-arg "COMMIT_DATE=${COMMIT_DATE}" \
  --build-arg "COMMIT_TIME=${COMMIT_TIME}" \
  --output "type=local,dest=${output}" \
  .

if [ ! -f "${output}/${package}" ]; then
  echo "ERROR: the package stage did not produce ${output}/${package}" >&2
  exit 1
fi

echo "==> Writing checksum"
(
  cd "$output"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$package" > "${package}.sha256"
  else
    shasum -a 256 "$package" > "${package}.sha256"
  fi
  cat "${package}.sha256"
)

echo "Package:  ${output}/${package}"
echo "Checksum: ${output}/${package}.sha256"
echo "Publish:  deploy/manual/publish.sh user@host:/path/to/deploy-root ${output}/${package}"
