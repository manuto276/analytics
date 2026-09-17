# nginx configuration

Two reference configurations:

| File | For |
|---|---|
| `deploy/examples/nginx-vhost.conf` | a managed PHP host, tarball deployment ([managed-php-hosts.md](managed-php-hosts.md)) |
| `deploy/docker/nginx/prod.conf` + `deploy/docker/nginx/snippets/` | the production container image ([docker.md](docker.md)) |

They differ mainly in how the SPA is served; everything else is the same.

## What must reach PHP

| Path | Handler |
|---|---|
| `^~ /api/` | PHP — the dashboard API and the server API |
| `^~ /t/` | PHP — the tracker script and the collection endpoints |
| `^~ /_nuxt/`, `^~ /_fonts/` | nginx, `public, max-age=31536000, immutable` |
| `^~ /_ops/` | PHP, restricted to loopback/private networks (see *Reserved paths* below) |
| everything else | the SPA — either `try_files $uri /index.html` or PHP |

Also:

```nginx
location ~ \.php$ { return 404; }     # never serve another PHP file directly
location ~ /\.    { deny all; }       # no dotfiles
client_max_body_size 1m;              # the application caps bodies at 64 KB (2 MB for CSV)
server_tokens off;
```

## `$realpath_root`

This is the one setting that is easy to get wrong and hard to diagnose.

The web root is `…/current/public`, and `current` is a symlink that a deploy replaces. The path
`…/current/public/index.php` therefore never changes, while the file behind it does. With
`opcache.validate_timestamps=0` — which is what you want in production, and what the container image
sets — OPcache keeps serving the **previous release's** compiled code, and there is no way to reload
PHP-FPM on a managed host.

Passing the resolved path fixes it: each release has its own OPcache entries and a deploy is picked up
on the next request.

```nginx
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
fastcgi_param SCRIPT_NAME     /index.php;
fastcgi_param DOCUMENT_ROOT   $realpath_root;
```

Symptom when it is missing: `./console status` reports the new release, `/api/v1/health` reports the
**old** commit, and the deploy's health check fails (and rolls back, with `auto_rollback`).

In the container images the same absolute path `/app` exists in the PHP and the nginx image, so
`$realpath_root` resolves identically on both sides.

The container's `php-fastcgi.conf` snippet also forwards `X-Forwarded-For`, `X-Forwarded-Proto` and
`X-Forwarded-Host`, and sets `REQUEST_SCHEME`/`HTTPS`.

## Anonymised logs

The application shortens the client address in its outermost middleware and never stores it. A default
nginx access log undoes that: it records the full address and the full User-Agent of every visitor,
for every `/t/e` request.

Define an anonymised format in the `http` context:

```nginx
log_format analytics_anon '[$time_iso8601] "$request_method $uri $server_protocol" '
                          '$status $body_bytes_sent $request_time';
```

No `$remote_addr`, no `$http_user_agent`, and `$uri` rather than `$request`, so the query string is
not logged either. Use it at least for `/t/`:

```nginx
location ^~ /t/ {
    access_log /home/site/logs/nginx/tracking.log analytics_anon;
    …
}
```

`access_log off;` is equally fine for `/t/`. The reference vhost uses the anonymised format for the
dashboard as well; client addresses are not needed there either, and the application's own audit log
records a shortened prefix for administrative actions.

If a reverse proxy or CDN sits in front, check its logging too — it is the first thing that sees the
address.

## SPA fallback: two options

**a. nginx serves `index.html` (the managed-host example)**

```nginx
location / { try_files $uri /index.html; }
location = /index.html { add_header Cache-Control "no-cache" always; }
location = /200.html   { add_header Cache-Control "no-cache" always; }
```

Fastest, one fewer PHP process per document. The document is served by nginx, so it does **not**
carry the application's Content-Security-Policy — the API responses still do, and the SPA's own
assets are same-origin, but you lose the document-level CSP. Add it in nginx if you want it, keeping
the inline-script hashes from `config/csp.php` in sync by hand.

**b. PHP serves it (the container image)**

```nginx
location / {
    include /etc/nginx/snippets/php-fastcgi.conf;
    fastcgi_pass php_upstream;
}
```

`Analytics\Kernel\Http\SpaFallbackAction` returns `public/index.html` with the full security header
set (CSP with the generated hashes), an `ETag` and `Cache-Control: no-cache, must-revalidate`, and
answers `304` on a matching `If-None-Match`. It also serves `/robots.txt` (`Disallow: /`). The route
is `GET /{path:(?!api/|t/).*}`, so `/api/` and `/t/` are never swallowed.

The container configuration serves static assets by extension with `try_files $uri @php`, so a missing
asset falls through to PHP and gets a JSON `404` instead of nginx's HTML page.

Both options are covered by `Analytics\Tests\Functional\Kernel\SpaFallbackTest`.

## Security headers

Responses produced by PHP already carry the application headers (`SecurityHeadersMiddleware`): CSP,
`Referrer-Policy`, COOP, CORP, `X-Frame-Options`, `Permissions-Policy`, `X-Content-Type-Options`, and
HSTS when `APP_URL` is https. nginx must not weaken them — do not add a second CSP to PHP locations.

For files nginx serves itself, add the baseline
(`deploy/docker/nginx/snippets/security-headers.conf`). Note that `add_header` is **not** inherited by
a location that declares its own, so the snippet must be included in every such location.

`/t/*` responses deliberately use a different profile: `Cross-Origin-Resource-Policy: cross-origin`
(the script is loaded from other sites) and `Referrer-Policy: no-referrer`.

## Reserved paths

`/_ops/` is restricted to loopback and private networks in both reference configurations, and
`OPS_TOKEN` is generated by `secrets:generate`. **No `/_ops/` endpoint is implemented** — in
particular there is no `POST /_ops/opcache-reset`. A request to `/_ops/anything` currently falls
through to the SPA fallback. The location block and the variable are there for a future use; nothing
depends on them today.

## TLS and redirects

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name stats.example.net;
    return 301 https://$host$request_uri;
}
```

`APP_URL` must be `https://` in production: `app:preflight` fails otherwise (unless the host is a
loopback address), and the session cookie only gets the `__Host-` prefix over HTTPS.

## Caching

| Location | Header |
|---|---|
| `/_nuxt/`, `/_fonts/` | `public, max-age=31536000, immutable` (content-hashed names) |
| other static assets | `public, max-age=3600` |
| `index.html` / SPA documents | `no-cache` (nginx) or `no-cache, must-revalidate` + `ETag` (PHP) |
| `/t/{key}.js` | set by the application: `public, max-age=300, stale-while-revalidate=600` + `ETag` |
| `/t/e`, `/t/forget` | `no-store` |
| API responses | `private, max-age=30` + `ETag` |

Never put a shared cache in front that ignores `private`. See
[../integration/caching-proxies.md](../integration/caching-proxies.md).

## Behind a reverse proxy or CDN

Forward the client address and list the proxy in `TRUSTED_PROXIES`, otherwise `ClientIpResolver`
ignores `X-Forwarded-For` and every visitor collapses into one shortened address per day:

```nginx
proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto $scheme;
proxy_set_header Host              $host;
```

```dotenv
TRUSTED_PROXIES=203.0.113.10,172.16.0.0/12
```

The resolver walks the chain from the right and takes the first address that is not trusted, so a
spoofed `X-Forwarded-For` from an untrusted client is ignored
(`Analytics\Tests\Unit\Shared\ClientIpResolverTest`).

## Checklist

- [ ] Web root is `…/current/public` (tarball) or `/app/public` (container)
- [ ] `$realpath_root` in `SCRIPT_FILENAME` and `DOCUMENT_ROOT`
- [ ] `^~ /api/` and `^~ /t/` reach PHP; `^~ /_nuxt/` does not
- [ ] SPA fallback in place and not swallowing `/api/` or `/t/`
- [ ] Anonymised or disabled access log for `/t/`
- [ ] `location ~ \.php$ { return 404; }` and dotfiles denied
- [ ] HTTPS with a redirect from port 80, `APP_URL` matching
- [ ] `TRUSTED_PROXIES` set if anything sits in front
- [ ] No shared page cache over the API

## Related

[managed-php-hosts.md](managed-php-hosts.md) · [docker.md](docker.md) · [tarball.md](tarball.md) ·
[../integration/caching-proxies.md](../integration/caching-proxies.md)
