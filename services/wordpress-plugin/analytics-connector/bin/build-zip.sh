#!/usr/bin/env bash
# Builds dist/analytics-connector-<version>.zip, the package WordPress installs: an
# `analytics-connector/` folder with the compiled admin app and without sources or tooling.
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n 's/^ \* Version: *//p' analytics-connector.php | tr -d '[:space:]')
[ -n "$version" ] || { echo "No Version in analytics-connector.php" >&2; exit 1; }

if [ "${SKIP_BUILD:-0}" != "1" ]; then
    npm ci --no-audit --no-fund
    npm run build
fi
[ -f build/admin.js ] || { echo "build/admin.js missing: run npm run build" >&2; exit 1; }

rm -rf dist && mkdir -p dist/analytics-connector
rsync -a --exclude-from=.distignore ./ dist/analytics-connector/
(cd dist && zip -qr "analytics-connector-$version.zip" analytics-connector)
rm -rf dist/analytics-connector
echo "dist/analytics-connector-$version.zip"
