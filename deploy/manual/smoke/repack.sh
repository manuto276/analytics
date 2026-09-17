#!/usr/bin/env bash
# Re-packs an analytics package under a new build timestamp (BUILD_INFO.json
# "version" rewritten, everything else unchanged) and writes its .sha256.
# Used by the smoke test to get a second, newer, valid package from one build.
#
# Usage: repack.sh <analytics-TS.tar.gz> [NEW_TS] [--output DIR]
#   NEW_TS defaults to the current UTC time (YYYYMMDDTHHMMSSZ); DIR defaults to
#   the directory of the source package. Prints the new package path.
set -euo pipefail

usage() {
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "$0"
}

source_pkg=""
new_ts=""
output=""
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) shift; output="${1:?--output needs a directory}" ;;
    --output=*) output="${1#--output=}" ;;
    -h|--help) usage; exit 0 ;;
    -*) echo "ERROR: unknown option: $1" >&2; exit 2 ;;
    *)
      if [ -z "$source_pkg" ]; then source_pkg="$1"
      elif [ -z "$new_ts" ]; then new_ts="$1"
      else echo "ERROR: unexpected argument: $1" >&2; exit 2
      fi
      ;;
  esac
  shift
done

[ -n "$source_pkg" ] || { usage >&2; exit 2; }
[ -f "$source_pkg" ] || { echo "ERROR: ${source_pkg} not found" >&2; exit 1; }

name="$(basename "$source_pkg")"
if ! [[ "$name" =~ ^analytics-([0-9]{8}T[0-9]{6}Z)\.tar\.gz$ ]]; then
  echo "ERROR: invalid package name: ${name}" >&2
  exit 2
fi
old_ts="${BASH_REMATCH[1]}"
new_ts="${new_ts:-$(date -u +%Y%m%dT%H%M%SZ)}"
if ! [[ "$new_ts" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; then
  echo "ERROR: invalid timestamp: ${new_ts}" >&2
  exit 2
fi
if [ "$new_ts" = "$old_ts" ]; then
  echo "ERROR: new timestamp equals the original (${old_ts})" >&2
  exit 1
fi
output="${output:-$(dirname "$source_pkg")}"
mkdir -p "$output"

work="$(mktemp -d "${TMPDIR:-/tmp}/analytics-repack.XXXXXX")"
trap 'rm -rf "$work"' EXIT
mkdir "$work/pkg"
tar -xzf "$source_pkg" -C "$work/pkg"

info="$work/pkg/BUILD_INFO.json"
[ -f "$info" ] || { echo "ERROR: BUILD_INFO.json not found in ${name}" >&2; exit 1; }
if ! grep -Eq "\"version\"[[:space:]]*:[[:space:]]*\"${old_ts}\"" "$info"; then
  echo "ERROR: BUILD_INFO.json version is not ${old_ts}" >&2
  exit 1
fi
sed -E "s/(\"version\"[[:space:]]*:[[:space:]]*)\"${old_ts}\"/\\1\"${new_ts}\"/" "$info" > "$work/BUILD_INFO.json"
cat "$work/BUILD_INFO.json" > "$info"

new_name="analytics-${new_ts}.tar.gz"
# COPYFILE_DISABLE keeps macOS bsdtar from adding AppleDouble (._*) members.
COPYFILE_DISABLE=1 tar -czf "${output}/${new_name}" -C "$work/pkg" .
(
  cd "$output"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$new_name" > "${new_name}.sha256"
  else
    shasum -a 256 "$new_name" > "${new_name}.sha256"
  fi
)
echo "${output}/${new_name}"
