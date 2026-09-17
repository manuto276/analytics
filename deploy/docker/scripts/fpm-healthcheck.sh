#!/bin/sh
# Container healthcheck for the PHP-FPM image.
#
# It queries the pool's own ping path over FastCGI (see php/fpm-pool.conf:
# ping.path = /ping, ping.response = pong). That answers inside the FPM master
# without booting the application, so it stays cheap and never touches MySQL;
# application-level health is `php bin/analytics health:check --json`, which the
# deploy console and monitoring run instead.
set -eu

ADDRESS="${FPM_PING_ADDRESS:-127.0.0.1:9000}"
PATH_="${FPM_PING_PATH:-/ping}"

response=$(
  env -i \
    SCRIPT_NAME="$PATH_" \
    SCRIPT_FILENAME="$PATH_" \
    REQUEST_METHOD=GET \
    REQUEST_URI="$PATH_" \
    QUERY_STRING= \
    cgi-fcgi -bind -connect "$ADDRESS" 2>/dev/null
) || {
  echo "php-fpm did not answer on ${ADDRESS}" >&2
  exit 1
}

case "$response" in
  *pong*) exit 0 ;;
  *)
    echo "unexpected ping response from ${ADDRESS}: ${response}" >&2
    exit 1
    ;;
esac
