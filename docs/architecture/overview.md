# Architecture overview

`analytics` is one PHP application plus one JavaScript tracker. It serves three kinds of traffic
from a single origin (`APP_URL`, e.g. `https://stats.example.net`):

- `/t/*` — the tracker script and the collection endpoints, called by browsers on tracked sites;
- `/api/v1/*` — the JSON API, used by the dashboard (session cookie) and by customer backends
  (`/api/v1/server/*`, API key);
- everything else — the static dashboard SPA.

## Components

| Component | Where | What it is |
|---|---|---|
| Backend | `services/api` | Slim 4 + PHP-DI, Doctrine ORM 3/DBAL 4/Migrations 3, Symfony Console. PHP ≥ 8.4.1 |
| Tracker | `services/tracker` | TypeScript → esbuild IIFE (ES2019), no runtime dependencies, ≤ 5.0 KB gzip |
| Dashboard | `services/dashboard` | Nuxt 4 + Nuxt UI, `ssr: false`, `nuxt generate` → static files copied into `services/api/public` |
| Database | MySQL 8.4 | configuration through the ORM, hot and analytic tables through DBAL only |
| Redis | optional (`REDIS_DSN`) | cache, rate-limit storage, locks, daily salt, ingestion queue |
| Deploy console | `deploy/manual/console` | extension-less PHP script that manages packages, releases and the `current` symlink |
| App console | `services/api/bin/analytics` | Symfony Console inside each release (migrations, jobs, admin) |
| WordPress plugin | `services/wordpress-plugin/analytics-connector` | optional, GPL-2.0-or-later, separate from the service |

## Request flow

```
Tracked site (www.example.com)                    Service (stats.example.net)
  <script src="/t/pk_XXXX.js">  ──── GET ───────▶  TrackingController::script
    tracker + window.__an_cfg                       ScriptBundleBuilder (ETag, 5 min cache)
  cookies via document.cookie on .example.com
  sendBeacon text/plain         ──── POST /t/e ──▶  ClientIp (truncate) → SecurityHeaders →
                                                    BodyParsing (64 KB, text/plain JSON) →
                                                    NoCookiesGuard → TrackingController::collect
                                                    → CollectService: PayloadParser v1, origin
                                                      check, rate limit, bot filter, UA class,
                                                      URL/PII sanitising, referrer/UTM/channel,
                                                      geo, visitor hash
                                                    → EventSink (SyncEventSink | RedisQueueEventSink)
                                                    → IngestBatchHandler: visit resolution,
                                                      events_raw, visits, visitors, touches,
                                                      consent counters, rollup_dirty
Customer backend  ── POST /api/v1/server/sites/{publicKey}/conversions ──▶ ApiKeyAuthMiddleware →
                                                    ConversionIngestHandler → AttributionResolver
cron rollup:run                                 ─▶  RollupRunner → RollupBuilder → rollup_* tables
Dashboard browser ── GET /api/v1/sites/{id}/reports/... ─▶ SameOrigin → Session → CSRF → Access →
                                                    ReportService → QueryPlanner (rollup | raw)
                                                    → ReportCache → JSON envelope
Dashboard browser ── GET /anything-else ────────▶  SpaFallbackAction → public/index.html
```

An optional first-party proxy on the tracked site (`/stats/` → `https://stats.example.net/t/`)
changes nothing server-side except that the client address arrives in `X-Forwarded-For`; see
[integration/first-party-proxy.md](../integration/first-party-proxy.md).

## Middleware stack

Registered in `src/Kernel/AppFactory.php`. Effective order, outermost first:

```
ClientIpMiddleware → RequestIdMiddleware → SecurityHeadersMiddleware → ErrorMiddleware
→ BodyParsingMiddleware → Routing → (per-group middleware) → action
```

| Route group | Group middleware |
|---|---|
| `/t/*` | `NoCookiesGuardMiddleware` (strips request `Cookie` and response `Set-Cookie`) |
| `/api/v1/server/*` | `ServerApiGroupMiddleware` → `ApiKeyAuthMiddleware` (bearer key, scope, site match, `server` rate limit) |
| `/api/v1/*` public (`auth/login`, `auth/mfa`, `auth/config`, password reset, invitation view/accept) | `SameOriginMiddleware` |
| `/api/v1/*` session | `SameOriginMiddleware` → `ApiGroupMiddleware` → `SessionMiddleware` → `CsrfMiddleware` → `AccessMiddleware` |
| fallback `GET /{path}` | `SpaFallbackAction` (excludes `api/` and `t/`) |

`SecurityHeadersMiddleware` has two profiles. On `/t/*` it sets only `X-Content-Type-Options`,
`Cross-Origin-Resource-Policy: cross-origin` and `Referrer-Policy: no-referrer`. Everywhere else it
sets a CSP (`script-src 'self'` plus the inline hashes from `config/csp.php` on HTML responses,
`default-src 'none'` on other responses), `Referrer-Policy: same-origin`, COOP, CORP, `X-Frame-Options:
DENY`, `Permissions-Policy`, and HSTS when `APP_URL` is https.

Rate-limit policies live in `Analytics\Shared\RateLimit\RateLimiter::POLICIES`:

| Policy | Limit | Key |
|---|---|---|
| `collect` | 300 / minute | site id + shortened IP |
| `collect_site` | 30000 / minute | site id |
| `script` | 600 / minute | shortened IP |
| `login_ip` | 30 / 15 minutes | shortened IP (and pending MFA session) |
| `login_email` | 10 / 15 minutes | sha256 of the email |
| `dashboard` | 600 / minute | user id |
| `server` | 1200 / minute | API key prefix |
| `public` | 60 / minute | `/t/forget` per site + shortened IP |

Storage is Redis when `REDIS_DSN` is set, otherwise the `cache_items` table.

## The five invariants

These are the properties the system is built around. Each is enforced in one place and covered by a
test.

### 1. The IP is shortened in the outermost middleware

`ClientIpMiddleware` (added last, so it runs first) resolves the client address with
`ClientIpResolver` (honouring `X-Forwarded-For` only from `TRUSTED_PROXIES`), truncates it with
`IpTruncator` (IPv4 → /24, IPv6 → /48, IPv4-mapped IPv6 treated as IPv4) and then **removes** the
original address: it deletes `REMOTE_ADDR` and all forwarding headers from the request before calling
the next handler. Later code can only read the `ip_prefix` request attribute.
`IpScrubbingProcessor` masks anything that still looks like an address in Monolog records.

Tests: `Analytics\Tests\Unit\Shared\IpTruncatorTest` (all methods),
`Analytics\Tests\Unit\Shared\ClientIpResolverTest::testMiddlewareExposesOnlyPrefixAndScrubsFullAddress`,
`Analytics\Tests\Unit\Shared\LogScrubbingTest::testMasksIpv4AndIpv6`,
`Analytics\Tests\Functional\Tracking\PrivacyInvariantsTest::testNoFullAddressOrUserAgentAnywhere`
(ingests a known IPv4 and IPv6 plus a known UA, then scans every column of every table and every log
line).

### 2. `/t/*` responses never carry `Set-Cookie`

`NoCookiesGuardMiddleware` wraps the whole `/t` group: it clears the request cookie params and the
`Cookie` header on the way in, and strips `Set-Cookie` from the response on the way out. The service
domain therefore never stores anything in the visitor's browser; all cookies are written by the
tracker with `document.cookie` on the tracked site.

Tests: `Analytics\Tests\Functional\Tracking\CollectTest::testTrackingEndpointsNeverSetOrReadCookies`,
`Analytics\Tests\Functional\Tracking\CollectTest::testBasePageviewIsStoredAnonymously` (asserts the
empty `Set-Cookie` header), tracker side
`services/tracker/test/storage.test.ts` → "never touches cookies, Web Storage or IndexedDB until the
visitor decides".

### 3. Base-level data is never joined with visitor ids, conversions or external data

`CollectService::draft()` writes `visitor_id` only when the payload level is `c`;
`IngestBatchHandler::insertEvents()` and `startVisit()` repeat the check
(`$d->level === TrackingLevel::Consented ? $d->visitorId : null`). `PayloadParser` drops `vid`/`sid`
for `l=b` payloads and downgrades a `c` payload whose ids are missing or malformed.
`recordConsentedVisit()` — the only writer of `visitors` and `attribution_touches` — is called only
from the consented paths. `AttributionResolver` looks up touches by `visitor_id` only, so a
conversion can never be linked to base-level rows.

Tests: `CollectTest::testBasePageviewIsStoredAnonymously` (asserts `visitor_id` is NULL),
`CollectTest::testConsentedBatchIsDowngradedWhenCookieLevelIsOffOrGpc`,
`Analytics\Tests\Unit\Tracking\PayloadParserTest::testConsentedLevelWithoutIdsIsDowngraded`,
`Analytics\Tests\Functional\Conversions\ServerConversionsTest::testAttributionFromVisitorTouchesAndCustomerRef`.

### 4. Base "visitors" over a range = the sum of daily uniques

Rollups store one row per `(site_id, day, …)` and every metric is a sum. `visitors` is counted per
day (`RawSelects::VISITORS_V`/`VISITORS_E`: `COUNT(DISTINCT visitor_hash)` plus the consented ids that
have no hash) and summed across days, which makes it *visitor-days*, not distinct people over the
range. That is what makes the rollups additive: a 30-day number is the sum of 30 daily numbers, with
no re-reading of raw data. True monthly uniques exist only for consented visitors, in
`rollup_consented_visitors_monthly`.

Tests: `Analytics\Tests\Integration\Reporting\RollupConsistencyTest::testRollupAndRawAgree` (the same
report computed from rollups and from raw data must match, for every report and parameter set) and
`::testRollupsAreIdempotentAndClearDirtyDays`.

### 5. The API exposes aggregates only

Every report goes through `ReportService::run()`, which returns grouped rows. There is no endpoint
that returns a row of `events_raw`, `visits`, `visitors`, `attribution_touches` or `conversions`. The
external read API (`GET /api/v1/server/sites/{publicKey}/content/{contentKey}/stats`) additionally
suppresses a group smaller than the site's `min_group_size`.

Tests: `Analytics\Tests\Functional\Identity\RbacMatrixTest::testEverySessionRouteIsDocumentedInOpenApi`
and `::testEverySessionRouteDeclaresAKnownPermission` (a new route without a declared permission fails
the build), `ServerConversionsTest::testContentStatsRespectMinimumGroupSize`,
`Analytics\Tests\Functional\Reporting\ReportsTest` (all methods assert aggregate shapes).

## Configuration

Every runtime setting is an environment variable read by `Analytics\Kernel\Settings`; the template is
`deploy/docker/env.example`, packaged as `.env.example`.

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `prod` | `prod`, `dev` or `test` |
| `APP_URL` | `http://localhost:8080` | public URL; drives origin checks, cookie `Secure`, snippets |
| `APP_SECRET` | — (required in prod) | base64 of 32 bytes; HMAC/key-derivation master secret |
| `APP_ENCRYPTION_KEYS` | — (required in prod) | `id:base64key[,…]`; last entry encrypts |
| `DATABASE_URL` | built from `DB_*` | MySQL DSN |
| `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` | `127.0.0.1`/`3306`/`analytics`/`analytics`/empty | used when `DATABASE_URL` is empty |
| `DB_PARTITIONING` | `true` | monthly partitions on `events_raw` and `visits` |
| `REDIS_DSN` | empty | enables Redis cache, locks, rate limits, salt and queue |
| `INGEST_MODE` | `sync` | `sync` or `queue` |
| `TRUSTED_PROXIES` | empty | CIDRs whose `X-Forwarded-For` is trusted |
| `RETENTION_MONTHS` | `13` | raw-data retention |
| `LOG_LEVEL` | `warning` in prod | Monolog level |
| `STORAGE_DIR`, `LOG_DIR`, `CACHE_DIR` | under `var/` | writable directories |
| `GEO_DB_PATH` | `<storage>/geo/dbip-country-lite.mmdb` | DB-IP Lite database |
| `GEO_DB_URL` | DB-IP monthly URL | download template for `geo:update`. Read with `getenv()`, **not** through `Settings`, so a value set only in `.env` is not picked up (the Dotenv loader runs with `usePutenv(false)`); export it as a real environment variable, or pass `geo:update --url=…` |
| `MAILER_DSN`, `MAIL_FROM` | empty / `analytics@localhost` | optional mailer |
| `SOURCE_URL` | the GitHub repository | AGPL source offer in the tracker header and dashboard |
| `OPS_TOKEN` | empty | generated by `secrets:generate`; **no endpoint consumes it yet** (see below) |
| `APP_TEST_CLOCK` | — | honoured only when `APP_ENV=test` |

## Not implemented

Things the plan mentions that the code does not contain:

- **Sibling subdomains count as outbound.** The automatic outbound-link rule compares the link host
  with `location.hostname` only (see [../integration/tracker.md](../integration/tracker.md)), so on
  `www.example.com` a link to `app.example.com` is reported as an outbound link. The tracker does not
  receive the site's domain list.
- **Redis-backed visit lookups.** `visit_lookup` is always a MySQL table; Redis is used for the cache,
  locks, rate limits, the daily salt and the ingestion queue only.
- **Email reports.** `symfony/mailer` is used for invitations and password resets only.
- **Consent receipts** exist as a table and a per-site switch (`consent_receipts_enabled`), written on
  a consent upgrade, but nothing reads them back; there is no receipt export.

See also [modules.md](modules.md), [data-model.md](data-model.md), [ingestion.md](ingestion.md),
[reporting.md](reporting.md) and [tracker.md](tracker.md).
