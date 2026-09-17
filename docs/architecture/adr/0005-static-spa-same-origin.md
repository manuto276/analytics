# 0005. A static SPA on the same origin as the API

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

The dashboard is a Nuxt application. Running it server-side would mean a Node process next to PHP —
impossible on a managed PHP host and an extra moving part in Docker. Serving it from a second origin
(a CDN, a `dashboard.` subdomain) would turn every API call into a cross-origin request: CORS
preflights, `SameSite=None` cookies, and a much weaker CSRF story.

The session cookie should be `__Host-`-prefixed, which requires `Path=/`, `Secure` and **no** `Domain`
attribute — that only works when the dashboard and the API share an origin.

## Decision

`ssr: false`, `nitro.preset: 'static'`, `nuxt generate`. The generated files are copied into
`services/api/public/` at build time (`pnpm generate:api`, which also writes the inline-script CSP
hashes into `services/api/config/csp.php`). The API, the tracker endpoints and the dashboard are all
served from `APP_URL`.

- Routing: `^~ /api/` and `^~ /t/` go to PHP; `/_nuxt/` and `/_fonts/` are served by nginx with
  `immutable` caching; everything else falls back to the SPA entry point.
- Two fallback options exist. nginx can serve `index.html` directly (`try_files $uri /index.html`),
  or the request can reach PHP and `Analytics\Kernel\Http\SpaFallbackAction` returns the same file
  with the application's security headers, an `ETag` and `Cache-Control: no-cache`. The Docker image
  uses the PHP path so the document carries the CSP; the managed-host example uses `try_files` and
  therefore loses the document CSP. Both are documented in [../../deploy/nginx.md](../../deploy/nginx.md).
- Sessions use an httpOnly cookie named `__Host-an_session` (`an_session` when `APP_URL` is not
  https), `SameSite=Lax`, plus an `X-CSRF-Token` header on every non-GET request and a
  `SameOriginMiddleware` check on `Origin`/`Sec-Fetch-Site`.
- During development, Nitro's `devProxy` forwards `/api` and `/t` to the stack, so the browser is
  same-origin there too.

## Consequences

- No CORS on the dashboard API, no preflights, and CSRF reduces to "same origin + header token".
- The SPA is static files: it can be cached aggressively, has no runtime of its own and cannot leak
  server state.
- The API types are generated from `docs/api/openapi.yaml` (`pnpm openapi-types`), and CI fails when
  they are stale, which keeps the contract honest without a running backend.
- The CSP for the dashboard document must list the hashes of Nuxt's inline scripts. They change on
  every build, so they are generated into `config/csp.php` at build time; a mismatch breaks the
  dashboard, so the header is covered by a functional test
  (`Analytics\Tests\Functional\Kernel\SpaFallbackTest::testDeepLinksServeTheDashboardWithSecurityHeaders`).
- A deep link like `/settings/consent` must be routed to the SPA without swallowing `/api/` or `/t/`:
  the fallback route is `GET /{path:(?!api/|t/).*}` and
  `SpaFallbackTest::testApiAndTrackerPathsAreNotSwallowed` guards it.
- `robots.txt` is served by the application with `Disallow: /`.
