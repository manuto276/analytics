#!/usr/bin/env bash
# WordPress smoke test: installs WordPress, activates analytics-connector, configures it
# with wp-cli and checks the rendered front end. Requires Docker with the compose plugin.
#
# Environment: WP_SMOKE_PORT (default 8089), WP_SMOKE_WP_IMAGE, WP_SMOKE_CLI_IMAGE,
# WP_SMOKE_DB_IMAGE, KEEP=1 to leave the stack running.
set -euo pipefail

cd "$(dirname "$0")"

port="${WP_SMOKE_PORT:-8089}"
base="http://localhost:${port}"
key="pk_SmokeTest0123456789ab"
stub="window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}"

compose() { docker compose -f compose.yml "$@"; }
wp() { compose run --rm -T cli wp "$@"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "ok - $*"; }

cleanup() {
  if [[ "${KEEP:-0}" != "1" ]]; then
    compose --profile cli down -v --remove-orphans >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

compose --profile cli down -v --remove-orphans >/dev/null 2>&1 || true
compose up -d --wait db wordpress

for _ in $(seq 1 60); do
  curl -fsS -o /dev/null "${base}/wp-admin/install.php" 2>/dev/null && break
  sleep 2
done
curl -fsS -o /dev/null "${base}/wp-admin/install.php" || fail "WordPress did not come up on ${base}"

wp core install --url="${base}" --title="Smoke" --admin_user=admin \
  --admin_password="smoke-$(date +%s)" --admin_email=admin@example.com --skip-email
wp plugin activate analytics-connector
wp option update analytics_connector_settings --format=json \
  "{\"service_url\":\"https://stats.example.net\",\"public_key\":\"${key}\",\"mode\":\"direct\",\"proxy_path\":\"/stats/\",\"skip_capability\":\"edit_posts\",\"global_name\":\"analytics\"}"
page_id="$(wp post create --post_type=page --post_status=publish --post_title=Privacy \
  --post_content='[analytics_consent_link label="Cookie & privacy"]' --porcelain | tr -d '\r')"

html="$(curl -fsS "${base}/")"
tag="$(grep -Eo "<script[^>]*src=['\"]https://stats\.example\.net/t/${key}\.js['\"][^>]*>" <<<"${html}" || true)"
[[ -n "${tag}" ]] || fail "tracker script tag missing"
grep -q "defer" <<<"${tag}" || fail "tracker script is not deferred: ${tag}"
pass "direct script tag: ${tag}"
grep -qF "${stub}" <<<"${html}" || fail "inline stub missing"
pass "inline stub present"
! grep -Eq "<b>(Warning|Notice|Deprecated|Fatal error)</b>" <<<"${html}" || fail "PHP notices on the homepage (WP_DEBUG)"
pass "no PHP notices"
head_html="${html%%</head>*}"
grep -qF "${key}.js" <<<"${head_html}" || fail "tracker script not in <head>"
stub_pos="$(grep -bo "window.analytics=window.analytics" <<<"${head_html}" | head -n1 | cut -d: -f1)"
tag_pos="$(grep -bo "id=\"analytics-connector-js\"" <<<"${head_html}" | head -n1 | cut -d: -f1)"
(( stub_pos < tag_pos )) || fail "inline stub must precede the tracker script"
pass "script and stub in head, stub first"

page="$(curl -fsS "${base}/?page_id=${page_id}")"
grep -qF '<a href="#analytics-consent" data-analytics-consent>Cookie &amp; privacy</a>' <<<"${page}" \
  || fail "consent shortcode not rendered"
pass "consent shortcode rendered"

wp option patch update analytics_connector_settings mode proxy
html="$(curl -fsS "${base}/")"
grep -Eq "src=['\"]${base}/stats/${key}\.js['\"]" <<<"${html}" || fail "proxy script tag missing"
pass "proxy script tag"

result="$(wp eval 'var_export( analytics_connector_track_conversion( "purchase" ) );' | tr -d '\r')"
[[ "${result}" == "false" ]] || fail "conversion without API key should return false, got ${result}"
pass "conversion without API key returns false"

block="$(wp eval 'echo do_blocks( "<!-- wp:analytics-connector/consent-link {\"label\":\"Privacy\"} /-->" );' | tr -d '\r')"
[[ "${block}" == '<a href="#analytics-consent" data-analytics-consent>Privacy</a>' ]] || fail "block render: ${block}"
pass "consent link block rendered"

menu="$(wp eval 'echo wp_json_encode( apply_filters( "nav_menu_link_attributes", array( "href" => "#analytics-consent" ), null, null, 0 ) );' | tr -d '\r')"
grep -qF '"data-analytics-consent":"true"' <<<"${menu}" || fail "menu link attribute: ${menu}"
pass "menu link attribute"

meta="$(wp eval 'add_filter( "analytics_connector_content_key", static fn () => "author:1" ); do_action( "wp_head" );' | tr -d '\r')"
grep -qF '<meta name="analytics:content" content="author:1">' <<<"${meta}" || fail "content meta tag missing"
pass "content meta tag"

echo "WordPress smoke test passed"
