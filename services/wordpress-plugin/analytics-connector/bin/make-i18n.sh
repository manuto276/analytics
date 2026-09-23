#!/usr/bin/env bash
# Regenerates the translations: languages/analytics-connector.pot from the PHP and the built admin
# app, then the .mo and the JSON the admin app loads for every languages/*.po.
#
# Run after `npm run build`. Needs Docker (WP-CLI's i18n commands in wordpress:cli).
# Updating an existing .po with the new strings: msgmerge -U languages/analytics-connector-it_IT.po languages/analytics-connector.pot
set -euo pipefail
shopt -s nullglob
cd "$(dirname "$0")/.."

wp() { docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/p -w /p wordpress:cli-php8.3 wp "$@"; }

wp i18n make-pot . languages/analytics-connector.pot --slug=analytics-connector --domain=analytics-connector \
    --exclude=node_modules,vendor,src,dist,docs,tests \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/manuto276/analytics/issues"}'
wp i18n make-mo languages
rm -f languages/analytics-connector-*-*.json
wp i18n make-json languages --no-purge

# WordPress looks each JSON up by the md5 of its script's path relative to the plugin
# (`build/admin.js`, the block's `blocks/consent-link/editor.js`); WP-CLI names them after paths of its own (the
# minified admin bundle comes out as "build/a.js"). Rename and relabel each by its source.
md5of() { printf '%s' "$1" | md5sum 2>/dev/null | cut -d' ' -f1 || md5 -qs "$1"; }
for f in languages/analytics-connector-*-*.json; do
    locale=$(basename "$f" .json | sed -E 's/^analytics-connector-(.+)-[0-9a-f]{32}$/\1/')
    if grep -q 'blocks\\/consent-link' "$f"; then script='blocks/consent-link/editor.js'; else script='build/admin.js'; fi
    target="languages/analytics-connector-$locale-$(md5of "$script").json"
    [ "$f" = "$target" ] && continue
    sed -E "s#\"source\":\"[^\"]*\"#\"source\":\"${script//\//\\\\/}\"#" "$f" > "$f.tmp" && rm "$f" && mv "$f.tmp" "$target"
    echo "$target ($script)"
done
