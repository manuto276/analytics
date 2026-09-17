# Caching proxies and page caches

The tracker works behind a page cache, but two rules have to be respected on the **tracked site**.
Reference snippets: `deploy/examples/varnish.vcl.snippet`.

## Rule 1 — do not vary or bypass the cache on `an_*` cookies

`an_consent`, `an_vid` and `an_sid` are written and read by JavaScript in the page. The HTML is
identical for every anonymous visitor, whatever those cookies say.

Most caches treat "request has cookies" as "do not cache", or add the cookie to the cache key. Either
turns a fully cacheable site into an uncacheable one as soon as the first visitor accepts, or splits
the cache per visitor. Strip them before the cache decision.

Varnish (VCL 4.1), inside `vcl_recv`, before any cookie-based pass or hash logic:

```vcl
if (req.http.Cookie) {
    set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)an_[A-Za-z0-9_]+=[^;]*", "");
    set req.http.Cookie = regsub(req.http.Cookie, "^;\s*", "");
    if (req.http.Cookie ~ "^\s*$") {
        unset req.http.Cookie;
    }
}
```

nginx `proxy_cache`: `an_*` is not in your cache key unless you put it there — check
`proxy_cache_key` and `proxy_cache_bypass`.

Cloudflare and similar CDNs: do not add `an_*` to the cache key, and do not list them under "bypass
cache on cookie".

WordPress cache plugins (WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache): do not add `an_*` to
the "cookies that prevent caching" or "cookies to vary on" lists.

**Exception.** If a specific URL is served by your backend and needs `an_vid` — a checkout confirmation
that posts a server-side conversion — exclude that URL from the stripping rather than keeping the
cookies everywhere:

```vcl
if (req.url !~ "^/checkout/complete") {
    # strip an_* here
}
```

## Rule 2 — bypass the proxy path

If you use a [first-party proxy](first-party-proxy.md), `/stats/` must not be cached as HTML, must not
be optimised, and must be forwarded untouched:

```vcl
if (req.url ~ "^/stats/") {
    return (pass);
}
```

`/stats/e` and `/stats/forget` are `Cache-Control: no-store` and must reach the backend on every
request. `/stats/pk_X.js` is `public, max-age=300, stale-while-revalidate=600` and may be cached by the
proxy — but never rewritten: the tracker derives its endpoint from its own `src`.

Also exclude `/stats/` from:

- HTML minification and JavaScript concatenation;
- "delay JavaScript until interaction" features — they break the consent banner and lose the initial
  pageview;
- CDN page rules that add query strings or strip headers.

## What the analytics service sends

| Response | Cache-Control |
|---|---|
| `GET /t/{key}.js` | `public, max-age=300, stale-while-revalidate=600`, plus a strong `ETag` and `Vary: Accept-Encoding` |
| `POST /t/e`, `POST /t/forget`, `OPTIONS /t/*` | `no-store` |
| `GET /api/v1/sites/{id}/reports/*` | `private, max-age=30` with an `ETag` |
| `GET /api/v1/health` | `no-store` |
| dashboard `index.html` | `no-cache, must-revalidate` with an `ETag` |
| `/_nuxt/*`, `/_fonts/*` | `public, max-age=31536000, immutable` |

A shared cache in front of the **analytics service** must honour `private` on the API responses. The
simplest correct configuration is to cache only `/t/*.js`, `/_nuxt/` and `/_fonts/`, and pass
everything else.

## Effects on the numbers

- A cached HTML page still executes the tracker: pageviews are not lost.
- `stale-while-revalidate` means a configuration or consent change can take up to about ten minutes to
  reach every visitor.
- If a proxy in front of the analytics service does not forward the client address (or is not listed
  in `TRUSTED_PROXIES`), every visit gets the same shortened address: base-level visitor counts
  collapse to roughly one per day and countries all become the proxy's. Check that first when
  "visitors" looks impossibly low.
- If a page cache *does* vary on `an_*`, the cache hit rate drops after visitors start accepting —
  which usually shows up as a site slowdown, not as an analytics problem.

## Verifying

```sh
# the cache must not vary on an_*: the same URL with and without the cookie should hit
curl -sI https://www.example.com/ | grep -i 'x-cache\|age'
curl -sI https://www.example.com/ -H 'Cookie: an_vid=AbCdEfGhIjKlMnOpQr_-12' | grep -i 'x-cache\|age'

# the proxy path must not be cached and must not set a cookie
curl -sI https://www.example.com/stats/pk_XXXXXXXXXXXXXXXXXXXXX.js | grep -i 'cache-control\|set-cookie'
```

## Related

[first-party-proxy.md](first-party-proxy.md) · [wordpress.md](wordpress.md) ·
[../privacy/cookies.md](../privacy/cookies.md) · [../deploy/nginx.md](../deploy/nginx.md)
