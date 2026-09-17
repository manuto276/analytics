# First-party proxy

By default the tracker is loaded from the analytics host (`https://stats.example.net/t/…`). You can
instead serve it from a path on the tracked site (`https://www.example.com/stats/…`) with a reverse
proxy. The tracker derives its endpoint from its own `src`, so nothing else changes.

Reference configuration: `deploy/examples/tracked-site-proxy.nginx.conf`.

## Why

- Content blockers and corporate filters match on third-party hostnames; a same-origin path is not on
  those lists.
- The tracked site's CSP needs no extra origin: `script-src 'self'; connect-src 'self'`.
- Some networks block unknown hosts outright.

It does **not** change what is collected. The requests are already cookie-less and
`credentials: 'omit'`; the proxy only changes which hostname the browser talks to. It is not a way to
track people who have opted out.

## nginx on the tracked site

```nginx
location ^~ /stats/ {
    proxy_pass https://stats.example.net/t/;
    proxy_http_version 1.1;
    proxy_ssl_server_name on;
    proxy_ssl_name stats.example.net;
    proxy_set_header Host stats.example.net;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;

    # The tracker is cookie-less server-side: never forward the site's cookies,
    # never let a Set-Cookie through.
    proxy_set_header Cookie "";
    proxy_hide_header Set-Cookie;

    proxy_connect_timeout 5s;
    proxy_read_timeout 10s;
    client_max_body_size 128k;
    access_log off;
}
```

The trailing slashes matter: `/stats/` → `/t/` maps `/stats/pk_X.js` to `/t/pk_X.js` and `/stats/e` to
`/t/e`.

## Snippet

```html
<script defer src="/stats/pk_XXXXXXXXXXXXXXXXXXXXX.js"></script>
<script>window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}</script>
```

`GET /api/v1/sites/{siteId}/snippet` returns this variant as `proxy_html` alongside the direct one.

## On the analytics service

Add the proxy's egress addresses to `TRUSTED_PROXIES` (comma-separated CIDRs or addresses):

```dotenv
TRUSTED_PROXIES=203.0.113.10,198.51.100.0/24
```

Otherwise `ClientIpResolver` ignores `X-Forwarded-For` and every visit appears to come from the proxy,
which collapses the visitor hashes of the whole site into one value per day and makes the country
always that of the proxy.

The address is still shortened immediately after it is resolved — `TRUSTED_PROXIES` decides *which*
address is shortened, never whether one is stored.

## Caching

The tracker script is `public, max-age=300, stale-while-revalidate=600` and may be cached by the
proxy. `/stats/e` and `/stats/forget` are `no-store` and must never be cached.

Any page cache in front of the tracked site must **bypass** `/stats/` entirely — see
[caching-proxies.md](caching-proxies.md). Do not let an optimisation plugin inline, concatenate or
rewrite the script: the endpoint is derived from the `src` attribute.

## Other servers

Apache:

```apache
SSLProxyEngine on
ProxyPass        /stats/ https://stats.example.net/t/
ProxyPassReverse /stats/ https://stats.example.net/t/
RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"
RequestHeader unset Cookie
```

Caddy:

```caddy
handle_path /stats/* {
    reverse_proxy https://stats.example.net {
        rewrite /t{uri}
        header_up Host stats.example.net
        header_up -Cookie
    }
}
```

A CDN or edge worker works too, as long as it forwards the client address in `X-Forwarded-For`, does
not cache `/stats/e`, and does not add cookies.

## Overriding the endpoint explicitly

The served configuration has an `ep` key for an explicit endpoint base. The backend currently always
sets it to `null` (`ScriptBundleBuilder::config()`), so the endpoint is always derived from the script
`src`. There is **no** setting to configure it per site.

## Verifying

```sh
curl -sI https://www.example.com/stats/pk_XXXXXXXXXXXXXXXXXXXXX.js   # 200, no Set-Cookie
curl -s -X POST https://www.example.com/stats/e \
  -H 'Origin: https://www.example.com' -H 'Content-Type: text/plain' \
  -d '{"v":1,"k":"pk_XXXXXXXXXXXXXXXXXXXXX","l":"b","e":[]}' -o /dev/null -w '%{http_code}\n'
# 400 invalid_payload — an empty event list is refused, which proves the path reaches the application
```

Then check in the database (or in the Realtime report) that new events carry varying
`visitor_hash` values rather than one per day: if they do not, `TRUSTED_PROXIES` is wrong.

The end-to-end suite covers this with the `proxy.site.test` fixture.
