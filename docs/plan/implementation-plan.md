# Analytics — Implementation Plan

> Status: draft v1 · Owner: repository maintainers · License of this repo: AGPL-3.0-or-later
> **First execution step:** initialise the repo (M0) and save this document as `docs/plan/implementation-plan.md`, so work can resume later. Tick checkboxes in §14 as milestones land.

## 1. Context

`analytics` is a standalone, self-hosted, privacy-first, multi-site web analytics service. It is an independent, generic product (not tied to any client). It provides:

- **Two tracking levels**: a *base* level that needs no consent and stores no cookies or persistent ids, and a *cookie* level that runs only after consent.
- **A consent banner built into the tracker.** The service domain never sets cookies; cookies are first-party on the tracked site.
- **Generic marketing features**: custom events and goals, a server-side conversions API, funnels, campaign attribution, campaign cost import (CAC/ROAS), content-group stats, cohorts.
- **A dashboard**: static Nuxt SPA (copy of the Nuxt UI dashboard template) on the same origin as the Slim JSON API.
- **Two deployment modes**: Docker, or a timestamp-versioned tarball with an extension-less deploy `console` for managed PHP hosts (CloudPanel-like: no Node, no sudo).

**Scale**: starts at ~10k visits/month per site; designed to grow to millions of events/month (batching, additive rollups, monthly partitioning, optional queue).

**Legal frame**: GDPR, ePrivacy, national regulator cookie guidance; main reference the Italian Garante guidelines of 10 June 2021 (consent-exempt analytics only if aggregate, single site, shortened IP, no combination with other sources). Unsettled points are marked **LEGAL REVIEW**.

## 2. Fixed decisions

| Area | Decision |
|---|---|
| Repo | `/Users/emanuelefrascella/Progetti/analytics`, `git init`, remote `git@github.com:manuto276/analytics.git`, public, AGPL-3.0-or-later. Top-level: `deploy/`, `services/`, `docs/` (+ root README, LICENSE, NOTICE, Makefile, `.github/`, `.editorconfig`, `.gitignore`). |
| Independence | No client-specific code, names or domains. Examples use `example.com` / `example.net`. |
| Language | Code, docs, commits in English. Dashboard i18n en + it. |
| Backend | PHP 8.4 (CI also 8.5), Slim 4.15, PHP-DI 7 + slim-bridge, Doctrine ORM 3 + DBAL 4 + Migrations 3, Symfony Console 8, MySQL 8.4. Redis optional; no APCu. |
| Frontend | Nuxt 4 + Nuxt UI 4 from `nuxt-ui-templates/dashboard` (MIT, attribution in NOTICE), pnpm, Unovis. `ssr:false`, `nuxt generate`, same-origin API. |
| Service URL | `APP_URL`, decided before deploy (examples `https://stats.example.net`). |
| Base-level visitors | Daily-rotating secret salt hash of (site id + shortened IP + UA), salt destroyed at rotation (Plausible-like). Per-site `visitor_hash_mode = daily_hash | pageviews_only`. **LEGAL REVIEW** (fingerprinting). |
| IP handling | Shortened before any use (IPv4 /24, IPv6 /48). Full IP and full UA never stored (DB, queue, app logs). |
| Geo | Country only, offline DB-IP Lite Country (CC BY 4.0), `geo:update` monthly; lookup on shortened IP in memory. |
| Retention | Raw events, visits, visitors, touches, conversions: 13 months. Rollups kept (configurable). Enforced by cron commands. |
| Package version | Build timestamp UTC: `analytics-20260917T142501Z.tar.gz` + `.sha256`; git commit inside. |
| Consoles | App console `bin/analytics` (in each release). Deploy manager `console` (extension-less, deploy root, outside releases). |
| Dashboard auth | Users with roles (global admin/member; site admin/viewer), argon2id, httpOnly session cookie, optional TOTP, invitations, first admin via console. |
| WordPress | Optional generic plugin `services/wordpress-plugin/analytics-connector` (GPL-2.0-or-later). |

## 3. Architecture

### 3.1 Components and data flow

```
Tracked site (www.example.com, app.example.com)          Service (APP_URL, e.g. stats.example.net)
 script /t/{publicKey}.js ─────────── GET ───────────────▶ ScriptAction (tracker + site config, ETag)
 tracker: pageviews, events, SPA, banner (shadow DOM)
 cookies via document.cookie on .example.com
 sendBeacon text/plain ────────────── POST /t/e ─────────▶ CollectAction
   (optional first-party proxy /stats/ on tracked site)     ClientIp→truncate → Origin check → rate limit
                                                            → PayloadParser v1 → Enrichment (bot, UA,
                                                            URL/referrer/UTM, channel, geo, hash)
                                                            → VisitResolver → EventSink (DBAL batch |
                                                            Redis queue → queue:work)
Customer backend ── POST /api/v1/server/.../conversions ─▶ ConversionIngestHandler (API key)
                                                          MySQL: events_raw, visits (partitioned),
                                                          visitors, touches, conversions
                                                          cron rollup:run → rollup_* (additive)
Dashboard user (browser) ── same origin ────────────────▶ / static SPA  ·  /api/v1/* JSON API
                                                          QueryPlanner (rollup|raw) → ReportCache
```

**Invariants (each has a test):**
1. The IP is shortened in the outermost request middleware; later code sees only `ipPrefix`.
2. `/t/*` responses never carry `Set-Cookie`.
3. Base-level data is never joined with visitor ids, conversions or external data.
4. Base "visitors" over a range = sum of daily uniques (visitor-days), so base rollups are additive.
5. The dashboard/API expose aggregates only; no endpoint returns individual visitor or conversion rows.

### 3.2 Repository layout

```
analytics/
├── .github/workflows/{ci,nightly,release,codeql}.yml  dependabot.yml
├── Makefile  LICENSE  NOTICE  README.md
├── deploy/
│   ├── docker/  Dockerfile  compose.base.yml  compose.dev.yml  compose.test.yml  compose.prod.yml
│   │            php/  nginx/{dev,test,prod}.conf  nginx/snippets/{anon-log,security-headers}.conf
│   │            certs/gen-certs.sh  cron/crontab
│   ├── manual/  build.sh  publish.sh  console  deploy.ini.example  tests/  smoke/
│   └── examples/ nginx-vhost.conf  tracked-site-proxy.nginx.conf  varnish.vcl.snippet  crontab.example
├── services/
│   ├── api/                 Slim backend
│   ├── dashboard/           Nuxt app
│   ├── tracker/             tracker + banner (TypeScript, esbuild)
│   ├── e2e/                 Playwright + fixture sites
│   └── wordpress-plugin/analytics-connector/
└── docs/                    see §12
```

### 3.3 Backend modules (`services/api/src`, namespace `Analytics\`)

Each module: `Domain/`, `Application/`, `Infrastructure/`, `Http/`. Deptrac: Domain depends only on Shared; modules talk via Application interfaces; Reporting reads via its own DBAL read models.

| Module | Main classes |
|---|---|
| Kernel | AppFactory, ContainerFactory (compiled in prod), ConsoleApplicationFactory, Settings, BuildInfo |
| Shared | Http\JsonResponder, ProblemDetails (RFC 9457), Validation\Input (hand-written request validation; cuyz/valinor was dropped); Crypto\SecretBox (XChaCha20-Poly1305, key ids), TokenGenerator, TokenHasher; Net\ClientIpResolver, IpTruncator, IpPrefix; Clock (psr/clock, FrozenClock); Doctrine types; Cache (Redis/DoctrineDbal/PhpFiles); RateLimit (symfony/rate-limiter); Lock (symfony/lock) |
| Sites | Site, SiteDomain, TrackingSettings, VisitorHashMode, DomainMatcher, SnippetRenderer, CreateSite, UpdateSite |
| Identity | User, GlobalRole, SiteRole, UserSiteRole, Invitation, AuthSession, TotpCredential, RecoveryCode, PasswordHasher, LoginService, LoginThrottle, SessionManager, TotpService, InvitationService, Authorizer |
| Tracking | ScriptAction, CollectAction, ForgetAction; PayloadParser, PayloadV1, EventDraft, EventType, TrackingLevel; BotFilter, UserAgentClassifier, UrlSanitizer, PiiScrubber, ReferrerClassifier, UtmExtractor, ChannelClassifier, GeoLocator; DailySaltProvider, VisitorHasher; VisitResolver, VisitStore (Dbal/Redis); IngestBatchHandler, EventSink (Dbal/RedisQueue), QueueWorker, DirtyDayMarker |
| Consent | ConsentConfig (revisioned), ConsentTexts, ConsentTheme, PublishConsentConfig, ConsentStatsRecorder, ConsentReceiptWriter (optional), ScriptBundleBuilder |
| Conversions | ApiKey, ApiKeyService, ConversionIngestHandler, CustomerRefHasher, AttributionResolver, Goal, Funnel, FunnelStep, CampaignCost, CostImporter (CSV) |
| Reporting | ReportQuery, DateRange, Interval, Comparison, Filter, QueryPlanner, Reports\*, Rollup\RollupRunner + builders, ReportCache, CsvExporter |
| Retention | PartitionManager, RetentionPolicy, RetentionPurger, SaltJanitor, JobRunRecorder |
| Audit / Health | AuditLogger; HealthChecker (db, pending migrations, rollup lag, salt, geo db age, disk) |

**Dependencies** — runtime: slim/slim, slim/psr7, php-di/php-di, php-di/slim-bridge, doctrine/{orm,dbal,migrations}, symfony/{console,cache,rate-limiter,lock,uid,clock,dotenv,mailer(optional)}, predis/predis, monolog/monolog, jaybizzle/crawler-detect, matomo/device-detector (LGPL-3.0), maxmind-db/reader, spomky-labs/otphp, bacon/bacon-qr-code. Dev: phpunit ^13, paratest, infection, league/openapi-psr7-validator, opis/json-schema, phpstan (+doctrine, strict-rules, phpunit, deprecation-rules), deptrac, rector, composer-require-checker, php-cs-fixer (PER-CS 2.0), roave/security-advisories.

**Doctrine**: attribute mapping, native lazy objects, PhpFilesAdapter caches. ORM for configuration/identity entities; **hot and analytic tables via DBAL only**; schema-assets filter keeps DBAL tables out of `migrations:diff`.

### 3.4 Middleware stack (outer → inner)

All routes: ErrorMiddleware (ProblemDetails) → RequestId → ClientIp (TRUSTED_PROXIES, truncation) → SecurityHeaders (dashboard/API profile vs tracking profile) → BodyParsing (64 KB cap, JSON as `text/plain` on `/t/e`) → Routing.

| Group | Middleware |
|---|---|
| `/t/*` | NoCookiesGuard, CollectOriginMiddleware (Origin/Referer host in `site_domains`, optional subdomains; per-origin CORS), RateLimit(collect: site+ipPrefix, global per site), `Cache-Control: no-store` |
| `/api/v1/auth/*` | SameOrigin, RateLimit(login: per email and ipPrefix, progressive lockout), optional Session |
| `/api/v1/*` | SameOrigin, Session (idle 12 h, absolute 30 d, MFA state), CSRF (`X-CSRF-Token`), RateLimit(dashboard), SiteAccess (`{siteId}` membership), per-route RequirePermission |
| `/api/v1/server/*` | ApiKeyAuth (`Bearer ak_<prefix>_<secret>`, scopes), RateLimit(server) |
| fallback `GET /{path}` | SpaFallbackAction → `public/index.html` |

Headers: dashboard CSP `default-src 'self'; script-src 'self' 'sha256-…'` (Nuxt inline hashes generated at build into `config/csp.php`), `style-src 'self' 'unsafe-inline'`, `frame-ancestors 'none'`, HSTS, `Referrer-Policy: same-origin`, COOP, nosniff, Permissions-Policy. Tracker script: `Cross-Origin-Resource-Policy: cross-origin`. Rate-limit storage: Redis if `REDIS_DSN`, else `cache_items` table.

## 4. Ingestion

### 4.1 Endpoints
- `GET /t/{publicKey}.js` — tracker with `window.__an_cfg` (published consent config, cookie domain, feature flags); ETag, `public, max-age=300, stale-while-revalidate=600`; bundle cached per site revision.
- `POST /t/e` — event batch → `202`; `OPTIONS` for the fetch fallback.
- `POST /t/forget` `{k, vid}` — erase cookie-level rows for a visitor → `202`.
- `POST /api/v1/server/sites/{publicKey}/conversions` — §4.8.

### 4.2 Payload v1
Source of truth `docs/api/tracking-payload.v1.schema.json` (shared by tracker and PHP tests; PHP hot path uses a hand-written parser).

```jsonc
{ "v":1, "k":"pk_…", "l":"b|c",
  "vid":"b64url(16B)", "sid":"b64url(16B)",   // only when l=c; stripped server-side if l=b
  "cv":3, "sw":1440,
  "e":[{ "id":"b64url(12B)", "t":"pv|ev|en|cu|cs", "u":"https://www.example.com/p?utm_source=x",
         "r":"https://referrer.example.org/", "a":1234, "n":"signup_click",
         "p":{"plan":"pro"}, "ck":"author:42", "ms":15432, "sp":80,
         "cs":"shown|accept|reject|dismiss|reopen",
         "lu":"https://www.example.com/landing", "lr":"https://referrer.example.org/" }] }   // lu/lr: landing page and referrer, cu only
```
Types: `pv` pageview · `ev` custom event · `en` engagement (engaged ms, scroll %) · `cu` consent upgrade · `cs` consent statistic (counters only). Limits: ≤ 50 events/batch; URL ≤ 2048; name `[a-z0-9_:.-]{1,64}`; ≤ 10 scalar props (key ≤ 32, value ≤ 100); `occurred_at = received_at − clamp(a, 0, 24h)`; unknown `v` → `400` (supports N and N−1). Idempotency: `INSERT IGNORE` on `(site_id, event_uid, local_day)`.

### 4.3 Enrichment order (in memory, per batch)
1. Site snapshot (cached 60 s).
2. Bot filter: CrawlerDetect + DeviceDetector bot, empty UA / no Accept-Language, excluded IP prefixes and paths (client also skips `navigator.webdriver`).
3. UA classification → browser/OS family + major, device class; full UA discarded.
4. `UrlSanitizer` (drop fragment unless hash routing; allow-listed query params, default `utm_*`, `ref`; click ids dropped) + `PiiScrubber` (emails, phones, long digit runs in paths/props).
5. `ReferrerClassifier` (own domains ignored; host → known source via `resources/referrers/*.json`).
6. `UtmExtractor` + `ChannelClassifier` (`direct, organic_search, paid_search, organic_social, paid_social, email, referral, campaign, internal`).
7. `GeoLocator` on shortened IP (NULL if db missing).
8. `VisitorHasher` (daily_hash sites): `sodium_crypto_generichash(site_id‖ip_prefix‖ua, salt_today, 16)` → first 8 bytes BIGINT UNSIGNED.
9. Visit resolution → event sink → dirty-day marker.

### 4.4 Daily salt
Redis `an:salt:{UTC date}` with EXPIREAT next midnight, or table `daily_salts(day PK, salt BINARY(32))`. `DailySaltProvider`: `INSERT IGNORE` today, re-read, delete `day < today` (no previous salt kept). Cron `salt:rotate` every 15 min enforces the invariant. Backups exclude `daily_salts`. Known effect: base visits split at UTC midnight (documented).

### 4.5 Visits
Visit key: cookie level `sid`; `daily_hash` sites the visitor hash; `pageviews_only` sites no sessions (visits estimated from entry pageviews, bounce/duration unavailable — metric availability matrix documented). Lookup `visit_lookup(site_id, visitor_key)` or Redis TTL 30 min. New visit on: no lookup, 30 min inactivity, local day change, different external campaign/source (per-site flag, default on).

### 4.6 Levels
- **Base**: may carry `visitor_hash`; never `visitor_id`; never touches, visitors or conversion links.
- **Consented**: `visitor_hash` + `visitor_id`; upserts `visitors`; writes `attribution_touches` (first visit, then each non-direct entry).
- Mid-page consent: `cu` carries landing URL/referrer kept in memory; consent on a later page loses original UTM (documented).

### 4.7 Write path
- `INGEST_MODE=sync` (default): one transaction per batch — salt (cached) → visit lookup `FOR UPDATE` → multi-row `INSERT IGNORE events_raw` → visit upsert → `INSERT IGNORE rollup_dirty` → commit.
- `INGEST_MODE=queue` (Redis): endpoint enriches, `RPUSH` anonymised drafts, `202`; `queue:work --max-time=55` from cron each minute, batches of 500.
- Growth path (ADR): queue → read replica → columnar reporting adapter behind Reporting read ports.

### 4.8 Server-side conversions API
`POST /api/v1/server/sites/{publicKey}/conversions`, scope `conversions:write`, object or array ≤ 100:
```jsonc
{ "id":"order-8812", "name":"purchase", "occurred_at":"2026-09-17T10:00:00Z",
  "visitor_id":"…",            // optional: an_vid cookie on same registrable domain, or analytics.getVisitorId()
  "customer_ref":"opaque-123", // optional; HMAC with per-site derived key before storage
  "value":{"amount_minor":4900,"currency":"EUR"}, "props":{"plan":"pro"},
  "declared_source":{"utm_source":"…"} }   // optional, separate model, LEGAL REVIEW
```
Response `202 {accepted, duplicates, rejected:[{index, error}]}`; idempotent on `(site_id, external_id)`. `AttributionResolver`: (1) earliest attributed conversion with the same `customer_ref`; (2) visitor first touch (`first_touch`) and last non-direct touch (both snapshotted as `attr_*` and `lnd_*`); (3) `unattributed` (still counted). Attribution snapshotted with model version; `conversions:reattribute` recomputes.

## 5. Data model (MySQL 8.4)

Conventions: utf8mb4 `utf8mb4_0900_ai_ci` (paths `utf8mb4_bin`); UTC `DATETIME(3|6)`; `local_day` in site timezone. **O** = ORM entity, **D** = DBAL-only.

### 5.1 Configuration and identity

| Table | Columns | Keys |
|---|---|---|
| sites (O) | id, public_key CHAR(24), name, timezone, currency CHAR(3), base_tracking_enabled, visitor_hash_mode, cookie_level_enabled, cookie_domain NULL, visitor_cookie_days SMALLINT (≤ 395), new_visit_on_campaign_change, dnt_mode, respect_gpc, hash_routing, allowed_query_params JSON, excluded_paths JSON, excluded_ip_prefixes JSON, content_contact_events JSON, min_group_size, consent_receipts_enabled, rollup_version, created/updated/archived_at | UNIQUE public_key |
| site_domains (O) | id, site_id FK, host, include_subdomains, created_at | UNIQUE (site_id, host); IDX host |
| users (O) | id, email, password_hash, display_name, global_role, locale, status, failed_logins, locked_until, password_changed_at, last_login_at, created/updated_at | UNIQUE email |
| user_site_roles (O) | user_id, site_id, role (admin/viewer), created_at | PK (user_id, site_id) |
| invitations (O) | id, email, token_hash BINARY(32), global_role, site_roles JSON, invited_by, expires_at, accepted_at, revoked_at, created_at | UNIQUE token_hash |
| auth_sessions (O) | id BINARY(32) (sha256 token), user_id, state (pending_mfa/active), csrf_secret, created_at, last_seen_at, idle_expires_at, absolute_expires_at, ip_prefix, ua_summary, revoked_at | IDX user_id, absolute_expires_at |
| totp_credentials (O) | user_id PK, secret_ciphertext, key_id, confirmed_at, last_used_step, created_at | |
| recovery_codes (O) | id, user_id, code_hash, used_at | IDX user_id |
| password_resets (O) | token_hash PK, user_id, expires_at, used_at | |
| api_keys (O) | id, site_id, name, prefix CHAR(8), secret_hash, scopes JSON, created_by, created_at, last_used_at, expires_at, revoked_at | UNIQUE prefix |
| goals (O) | id, site_id, name, type (pageview/event/conversion), match JSON | UNIQUE (site_id, name) |
| funnels / funnel_steps (O) | funnels: id, site_id, name, scope (visit/visitor), window_days · steps: funnel_id, position, goal_id | PK (funnel_id, position) |
| campaign_costs (O) | id, site_id, day_from, day_to, channel, utm_source/medium/campaign, amount_minor, currency, note, import_batch_id, created_by, created_at | IDX (site_id, day_from) |
| audit_log (O) | id, occurred_at, actor_type, actor_id, action, site_id, target_type, target_id, metadata JSON (redacted), ip_prefix | IDX (site_id, occurred_at), (actor_type, actor_id, occurred_at) |
| consent_configs (O) | id, site_id, revision, consent_version (+1 only on material change), status (draft/published/archived; published immutable), texts JSON per locale, policy_urls JSON, default_locale, theme JSON, accepted_ttl_days (180), rejected_ttl_days (180), show_floating_reopen, published_at, published_by | UNIQUE (site_id, revision) |
| cache_items (D) | Symfony DoctrineDbalAdapter schema | |

### 5.2 Analytic tables (DBAL)

| Table | Columns | Keys / partitioning |
|---|---|---|
| daily_salts | day, salt BINARY(32), created_at | PK day |
| events_raw | id BIGINT AI, local_day, site_id, event_uid BINARY(12), occurred_at, received_at, level, type, name NULL, visit_id, is_entry, visitor_hash NULL, visitor_id BINARY(16) NULL, host, path, page_hash BINARY(8), query, referrer_host, channel, source, utm_source/medium/campaign/content/term, browser, browser_major, os, os_major, device, country CHAR(2), content_key, engagement_ms, scroll_pct, props JSON | PK (id, local_day); UNIQUE (site_id, event_uid, local_day); IDX (site_id, local_day, type), (site_id, received_at), (site_id, visitor_id, local_day); RANGE COLUMNS(local_day) monthly + pmax |
| visits | id, local_day, site_id, level, started_at, last_activity_at, visitor_hash, visitor_id, entry_host, entry_path, entry_page_hash, exit_page_hash, pageviews, events, engagement_ms, is_bounce, channel, source, referrer_host, utm_*, browser, os, device, country, entry_content_key | PK (id, local_day); IDX (site_id, local_day), (site_id, visitor_id, started_at); monthly partitions |
| visit_lookup | site_id, visitor_key BINARY(16), visit_id, visit_day, last_activity_at, source_key BINARY(8) (campaign-change detection) | PK (site_id, visitor_key); IDX last_activity_at |
| visitors | site_id, visitor_id, first_seen_at, last_seen_at, first_touch_id, visits, consent_version | PK (site_id, visitor_id) |
| attribution_touches | id, site_id, visitor_id, visit_id, visit_day, touched_at, is_first, channel, source, referrer_host, utm_*, landing_host, landing_path | IDX (site_id, visitor_id, touched_at) |
| conversions | id, site_id, external_id, name, origin (server/browser), occurred_at, local_day, received_at, visitor_id, customer_ref BINARY(32), value_minor, currency, props JSON, attr_model_version, attr_via, attr_touch_id, attr_touched_at, attr_channel, attr_source, attr_utm_*, lnd_touch_id, lnd_touched_at, lnd_channel, lnd_source, lnd_utm_* (last non-direct), declared_source JSON | UNIQUE (site_id, external_id); IDX (site_id, name, local_day), (site_id, customer_ref, occurred_at), (site_id, visitor_id) |
| consent_stats_daily | site_id, day, consent_version, shown, accepted, rejected, dismissed, reopened | PK (site_id, day, consent_version) |
| consent_receipts (optional) | id, site_id, visitor_id, consent_version, decision, decided_at | IDX (site_id, visitor_id) |
| rollup_dirty | site_id, day, first_marked_at (rollup lag), marked_at | PK (site_id, day) |
| job_runs | id, job, started_at, finished_at, status, message, stats JSON | IDX (job, started_at) |

Partitioned tables have no foreign keys (integrity in app + tests); partitions managed by `partitions:maintain`, not migrations; `DB_PARTITIONING=false` fallback uses chunked DELETE for retention.

### 5.3 Rollups (PK starts with `(site_id, day)`, additive metrics)

| Table | Extra PK | Metrics |
|---|---|---|
| rollup_overview_daily | — | pageviews, visits, visitors NULL, bounces, engagement_ms, events, consented_visits, conversions, revenue_minor |
| rollup_pages_daily | page_hash | host, path, pageviews, visits, visitors, entries, exits, entry_bounces, engagement_ms |
| rollup_landing_daily | page_hash, channel | entries, bounces |
| rollup_sources_daily | channel, source_hash | source, visits, visitors, bounces, pageviews, engagement_ms |
| rollup_campaigns_daily | utm_hash | utm_*, visits, visitors, bounces, pageviews |
| rollup_tech_daily | dimension, value | visits, visitors, pageviews |
| rollup_geo_daily | country | visits, visitors, pageviews |
| rollup_events_daily | name, prop_key, prop_value_hash | prop_value, occurrences, visits |
| rollup_content_daily | content_key, channel | pageviews, visits, visitors, contacts |
| rollup_conversions_daily | name, attr_channel, attr_hash | attr_source, attr_utm_*, count, value_minor, attributed |
| rollup_consented_visitors_monthly | month, dimension, value_hash | value, visitors (true uniques) |

Builders: DELETE + `INSERT … SELECT` per `(site_id, day)` in one transaction (idempotent), then bump `rollup_version` (cache invalidation).

### 5.4 Migrations policy
Migration 1: ORM tables (generated, reviewed). Migration 2: DBAL/partitioned tables in raw SQL (partitions current month → +3 + pmax). Forward-only (`down()` throws); DDL non-transactional. Expand/contract: each release's schema works with the previous release's code; The migration test suite rejects `DROP COLUMN`, `RENAME`, `DROP TABLE` and `MODIFY … NOT NULL` without a default unless the line is marked `contract-ok`.

## 6. Reporting API

Common params: `period` (`today, yesterday, 7d, 30d, 90d, month, last_month, 12mo, year, custom` + `from`/`to`), `interval` (`hour` ≤ 2 days raw only, `day, week, month`), `compare` (`none, previous_period, previous_year`), `filter[dim][op]=value` (dims `page, entry_page, exit_page, host, channel, source, referrer, utm_*, device, browser, os, country, event, content, level`; ops `is, is_not, contains, prefix, glob`), `limit` ≤ 1000, `cursor`, `sort`; `Accept: text/csv` for exports.

Envelope: `{data, meta:{site_id, range, compare_range, interval, source: rollup|raw|mixed, availability:{visitors,bounce,duration}, generated_at, cache}}`.

**Auth/admin**: `POST auth/login` (ok | mfa_required), `auth/mfa`, `auth/logout`, `GET auth/me`, `POST auth/password`, `auth/password/forgot|reset` (mailer only), `auth/totp/setup|confirm`, `DELETE auth/totp`, `POST auth/totp/recovery-codes`, `GET/DELETE auth/sessions`, `GET/POST invitations/{token}(/accept)`; CRUD users, invitations, sites (+ domains, members, `GET snippet`); consent `GET`, `PUT draft`, `POST publish {material_change}`, `GET history`; per-site CRUD api-keys, goals, funnels, costs (+ `POST costs/import` CSV with dry-run preview); `GET audit-log`, `GET health`.

**Reports** (`/api/v1/sites/{siteId}/reports/…`):

| Report | Output | Data |
|---|---|---|
| overview | visitors, visits, pageviews, views/visit, bounce rate, avg duration, conversions, revenue, consent rate, deltas | rollup |
| timeseries | metrics per interval + comparison | rollup (raw for hour) |
| pages `kind=top|entry|exit` · landing-pages | | rollup |
| sources `group=channel|source|referrer` · campaigns | | rollup |
| tech `group=device|browser|os` · countries | | rollup |
| events · events/{name}/props | | rollup |
| content `prefix=` | views, visitors, contacts, source split per content key | rollup |
| realtime | active visitors (5 min), pageviews/min (30 min), top pages/sources | raw, 10 s cache |
| goals · conversions | counts and value | rollup |
| funnels/{id} | step counts, conversion, drop-off, breakdown | raw + conversions, cached |
| attribution `model=first_touch|last_non_direct|declared` `group=channel|source|campaign` `windows=30,90` `base=` `target=` | visits, base/target conversions, revenue, cost, CAC, ROAS, % unattributed | conversions + touches + costs |
| cohorts `cohort=week|month` | returning visitors (cookie level) | raw ≤ 13 mo, monthly rollup after |
| consent | shown/accepted/rejected per day and version | consent_stats_daily |

External read API: `GET /api/v1/server/sites/{publicKey}/content/{contentKey}/stats?days=30` (scope `stats:read`, `min_group_size` suppression). Planner: rollups when one rollup covers all dims/filters, else raw within retention, else `422 filter_unavailable_for_range`. Cache: TagAwareAdapter keyed by (site, report, params, rollup_version); TTL 60 s if range includes today, else 24 h; `Cache-Control: private, max-age=30` + ETag. Every report query EXPLAIN-checked (no full scans of `events_raw`/`visits`).

## 7. Tracker and consent banner (`services/tracker`)

### 7.1 Build and budget
TypeScript → esbuild IIFE (ES2019), no runtime deps. `size-limit`: total ≤ 5.0 KB gzip (core ≤ 2.6, banner ≤ 2.4); fallback lazy `banner.<hash>.js`. Header `/*! analytics | AGPL-3.0-or-later | source: <repo url> */`. Modules: index, config, ids, cookies, consent, transport, collector, spa, dom, api, banner/{banner,styles,template}.

### 7.2 Integration and API
```html
<script defer src="https://stats.example.net/t/pk_XXXX.js"></script>
<script>window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}</script>
```
- Global `window.analytics`, fallback `window.__analytics` on collision; configurable per site.
- API: `track(name, props?)`, `pageview({url?})`, `setContent(key)`, `consent.open()`, `consent.get()` → `{status, version, decidedAt}`, `consent.set('accepted'|'rejected')`, `consent.onChange(cb)`, `consent.forget()`, `getVisitorId()` (null without consent).
- Declarative: `data-analytics-consent` (opens preferences), `data-analytics-event="name"` + `data-analytics-prop-*` (click events), `<input data-analytics-visitor>` (filled after consent), `<meta name="analytics:content" content="author:42">`.
- SPA: patch `pushState`/`replaceState`, `popstate` (+ `hashchange` if hash routing), skip duplicate consecutive URLs.
- Engagement: visible time via `visibilitychange`, max scroll depth, sent as `en` on `pagehide`/SPA navigation.
- Transport: queue, flush after 1 s idle / 10 events / `visibilitychange=hidden` / `pagehide`; `navigator.sendBeacon` text/plain blob, fallback `fetch(keepalive, credentials:'omit')` with one retry.
- Skips: `navigator.webdriver`, prerender (wait activation), `file:`/localhost unless configured, excluded paths; `dnt_mode`, `respect_gpc` (GPC = reject: no banner, cookie level off).
- **Automatic events** (per-site switches): the scope still to be confirmed with the owner (outbound links, file downloads, form submits); until decided they are planned for M12.

### 7.3 Consent state and cookies
Set via `document.cookie` on the tracked site, `Domain=<cookie_domain>` (probe longest writable suffix if not configured; probe cookie deleted at once), `Path=/; SameSite=Lax; Secure`.

| Cookie | Written when | Value | Lifetime |
|---|---|---|---|
| `an_consent` | after a choice only | `1.<consentVersion>.<a|r>.<decidedAt epoch-days base36>` | accept `accepted_ttl_days`; reject 180 d |
| `an_vid` | after accept | 22-char base64url (128 random bits) | `visitor_cookie_days` (≤ 395), not auto-extended |
| `an_sid` | after accept | 22-char base64url | 30-min sliding |

State `unknown` (no/malformed/expired cookie or version < published) → banner + base events; `accepted` → cookie level; `rejected` → base only, delete `an_vid`/`an_sid` on all candidate domains. Before a choice nothing is written; only `an_consent` is read. `consent.set` sends a `cs` counter event.

### 7.4 Banner
Shadow DOM host `<div data-analytics-banner>` prepended to body; `adoptedStyleSheets` (strict CSP friendly) with `<style>` fallback; inherited font, theme from config. Accept and Reject native buttons with identical style; Close (×) and Esc = reject; scrolling is not consent; no blocking overlay. `role="dialog"`, `aria-modal="false"`, labelled/described, visible focus, `prefers-reduced-motion`, `forced-colors`; editor refuses contrast < 4.5:1. Locale from `<html lang>` → site default. Reopen via `consent.open()`, `data-analytics-consent`, optional floating button. Reject not re-asked for 180 d unless `consent_version` increases.

### 7.5 Visitor id to backend
Same registrable domain: backend reads `an_vid` cookie. Otherwise frontend sends `getVisitorId()` (hidden input / header). Backend calls the conversions API; no id → counted, unattributed.

### 7.6 Optional first-party proxy
`deploy/examples/tracked-site-proxy.nginx.conf`: `location /stats/ { proxy_pass https://stats.example.net/t/; proxy_set_header X-Forwarded-For …; }`; snippet loads `/stats/pk_XXXX.js`, tracker derives endpoint from its own `src`. Service lists proxy IP in `TRUSTED_PROXIES`; page caches bypass the path and ignore `an_*` cookies (Varnish snippet).

## 8. Dashboard (`services/dashboard`)

Base: `nuxt-ui-templates/dashboard` imported as-is (pinned commit, MIT notice in NOTICE); remove `server/api` mocks. `ssr:false`, `nitro.preset:'static'`, `nuxt generate` → `.output/public` copied to `public/` at package time. Dev: `nitro.devProxy` sends `/api` and `/t` to nginx (same origin). Keep `@unovis/vue`, `date-fns`, `@vueuse/nuxt`, `zod`; add `@nuxtjs/i18n`, `openapi-typescript`, `@nuxt/test-utils`, `vitest`, `@vue/test-utils`, `happy-dom`, `@nuxt/eslint`, `vue-tsc`.

| Template element | Becomes |
|---|---|
| `layouts/default.vue` | same layout; nav: Overview, Pages, Sources, Campaigns, Audience, Events, Goals & Conversions, Funnels, Attribution, Retention, Realtime, Settings |
| `TeamsMenu` | `SitesMenu` (site selector, "Add site" for admins, `?site=` in URL) |
| `UserMenu` | kept + locale switch, colour mode, logout, build/commit, source link (AGPL) |
| `NotificationsSlideover` | `JobsSlideover` (rollup lag, stale geo DB, pending consent draft) |
| `pages/index.vue` + Home* components | Overview: stats with deltas, Unovis chart with dashed comparison, date range, interval, compare, top pages/sources |
| `pages/customers.vue` (UTable) | `pages`, `sources`, `campaigns`, `audience`, `events` (shared `ReportTable`: tabs, filters, CSV) |
| `pages/inbox.vue` | `realtime` (live pages/sources + per-minute chart) |
| `settings/*` | `index` (site: name, domains, timezone, levels, hash mode with legal notice, query params, exclusions), `members`, `consent` (per-locale editor, theme, live preview with real banner, publish with material-change toggle, history, stats), `goals`, `funnels`, `costs` (manual + CSV import), `api-keys`, `security` (password, TOTP + recovery codes, sessions) |
| new `layouts/auth.vue` | `login`, `login/mfa`, `invite/[token]`, `password/forgot`, `password/reset/[token]` |
| new | `/admin/users`, `/admin/sites`, `/admin/audit` |

Code: `useApi` (`$fetch.create` baseURL `/api/v1`, same-origin credentials, CSRF header; 401 → `/login`, 403 toast, 422 field errors); `useAuth`, `useSites`; `useReportQuery` (site, period, range, interval, compare, filters ↔ URL); `useReport(name)` (`useAsyncData`, keeps previous data, exposes availability); `auth.global.ts`; types generated from `docs/api/openapi.yaml` (CI fails if stale); `@nuxtjs/i18n` `no_prefix`, lazy `en.json`/`it.json`, Nuxt UI locale bound, `Intl` formatting in site timezone. Hosting: nginx `location / { try_files $uri /index.html; }`, `^~ /api/` and `^~ /t/` → `index.php`; Slim SPA fallback; `/_nuxt/*` immutable, `index.html` no-cache.

## 9. App console (`bin/analytics`) and schedule

| Command | Purpose |
|---|---|
| `migrations:*` | Doctrine Migrations (status, migrate, list, diff, generate, execute, version, sync-metadata-storage) |
| `orm:validate-schema`, `orm:info` | dev/CI |
| `app:preflight` | PHP ≥ 8.4.1, extensions (pdo_mysql, sodium, intl, mbstring, opcache), argon2id, env keys, writable dirs, DB, MySQL ≥ 8.4 |
| `cache:warmup` / `cache:clear` | container, routes, Doctrine metadata, CSP hashes |
| `health:check [--json]` | non-zero on failure |
| `secrets:generate`, `secrets:rotate-key` | key ring, re-encrypt TOTP secrets |
| `user:create-admin --email [--password-stdin]`, `user:list`, `user:disable`, `user:set-password`, `user:reset-2fa` | first admin, recovery |
| `invitation:create --email --role [--site]` | prints link (no mailer needed) |
| `site:create --name --domain=… [--timezone] [--hash-mode] [--cookie-domain]`, `site:list`, `site:show`, `site:domain:add/remove` | prints public key + snippet |
| `api-key:create --site --scopes`, `api-key:revoke` | secret shown once |
| `salt:rotate` | salt invariant |
| `rollup:run [--site]`, `rollup:rebuild --site --from --to` | dirty days; rebuild after timezone change |
| `partitions:maintain [--ahead=3]` | future partitions |
| `retention:purge [--dry-run]` | raw 13 months; sessions, invitations, resets; audit 24 months; job_runs 90 days; refuses dirty days |
| `conversions:reattribute --site [--from]` | |
| `geo:update [--force]`, `geo:lookup <ip>` | DB-IP Lite monthly, atomic swap |
| `queue:work --max-time=55` | queue mode only |
| `jobs:status` | last runs, lag |
| `dev:seed --site --days --events [--seed]` | refuses in prod |

All scheduled jobs use `symfony/lock` and record `job_runs`. Cron (through `current`, low priority `nice -n 10`):
```
*/5 * * * *  php bin/analytics rollup:run -q
*/15 * * * * php bin/analytics salt:rotate -q
* * * * *    php bin/analytics queue:work --max-time=55 -q     # only if INGEST_MODE=queue
20 3 * * *   php bin/analytics partitions:maintain -q && php bin/analytics retention:purge -q
40 4 5 * *   php bin/analytics geo:update -q
```

## 10. Deployment

### 10.1 Build (`deploy/manual/build.sh`)
1. Refuse dirty tree unless `--allow-dirty`; `TS=$(date -u +%Y%m%dT%H%M%SZ)`, `COMMIT=$(git rev-parse HEAD)`.
2. `docker buildx build -f deploy/docker/Dockerfile --target package --build-arg BUILD_TS --build-arg COMMIT --output type=local,dest=dist .` (host needs neither PHP nor Node; prod image shares stages): `git archive` context → `composer install --no-dev --classmap-authoritative` → `pnpm install --frozen-lockfile` + tracker build + `nuxt generate` → copy SPA and tracker into `public/`/`resources/` → write `BUILD_INFO.json` and `REVISION` → strip tests/dev config → reproducible tar (`--sort=name --owner=0 --group=0 --mtime=@<commit time>`, `gzip -n`).
3. Output `dist/analytics-$TS.tar.gz` + `dist/analytics-$TS.tar.gz.sha256` (`sha256sum` format).
4. Optional `publish.sh user@host:/path`: scp both into `packages/`, ssh `./console deploy`.

Tar layout (no wrapper dir):
```
BUILD_INFO.json   {"name":"analytics","version":"<TS>","commit":"…","commit_date":"…","built_at":"…",
                   "php":">=8.4.1","extensions":[…],"migrations":["Version…",…],"tracker_sha256":"…"}
REVISION  LICENSE  NOTICE  .env.example
bin/analytics  config/  migrations/  resources/  src/  vendor/
public/ index.php  index.html  200.html  _nuxt/  favicon.ico  robots.txt
var/cache/ (empty)          # var/log, var/storage → shared/ at deploy
deploy/console              # copy of the deploy manager (self-update)
deploy/examples/
```

### 10.2 Server layout and the two consoles
```
<deploy_root>/                 e.g. /home/<site-user>/htdocs/stats.example.net
├── console                    # deploy manager: PHP 8.4, no extension, no composer deps, chmod 750
├── deploy.ini                 # php_binary, keep_releases=5, keep_packages=3, health_url, auto_rollback, backup_before_migrate, shared items
├── packages/                  # analytics-<TS>.tar.gz + .sha256
├── releases/<TS>/
├── shared/  .env (0600)  var/log/  var/storage/{geo,locks,backups}/
├── current -> releases/<TS>   # web root = current/public
└── .deploy/  lock  history.jsonl
```
`console` manages releases and must work when the current release is broken, so it lives outside releases with no vendor deps; `bin/analytics` is the per-release app console. Distinct names avoid running migrations with the wrong console; `./console app <args>` passes through to `current/bin/analytics`.

Commands: `init`, `deploy [package] [--no-migrate] [--backup] [--no-health] [--dry-run]`, `verify <package>`, `list`, `status`, `rollback [release] [--force]`, `cleanup [--keep=N] [--packages]`, `app <args>`, `self-update`, `help`, `--version`.

Deploy flow:
1. `flock` `.deploy/lock`.
2. Resolve package (`^analytics-\d{8}T\d{6}Z\.tar\.gz$`, newest by default); verify SHA-256 with `hash_equals`.
3. List entries; reject absolute paths, `..`, symlinks escaping; extract to `releases/.tmp-<TS>` (`tar -xzf`, PharData fallback).
4. Read `BUILD_INFO.json`; check PHP version/extensions with `php_binary`; `REVISION` matches.
5. Link shared items with relative symlinks.
6. `bin/analytics app:preflight` and `cache:warmup`.
7. Optional `mysqldump --single-transaction` (excluding `daily_salts`) into `shared/var/storage/backups/`.
8. `bin/analytics migrations:migrate --no-interaction --allow-no-migration`.
9. Rename tmp → `<TS>`; `symlink(releases/<TS>, current.tmp)` + `rename()` over `current` (atomic).
10. Health check (`health_url` returns new commit, 5 tries/10 s); on failure with `auto_rollback` switch back and exit non-zero (migrations never rolled back; compatible by policy).
11. Append `history.jsonl`, cleanup (never current or previous), release lock.

Rollback only moves the symlink; warns and requires `--force` when the DB has migrations unknown to the target release. Permissions: site user owns everything (php-fpm pool runs as it); dirs 0750, files 0640, `.env` 0600, `console` 0750. No sudo/FPM reload: vhost uses `fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; DOCUMENT_ROOT $realpath_root` so OPcache picks new real paths; optional token-protected `POST /_ops/opcache-reset` (localhost only). nginx access log for `/t/` off or anonymised format.

Managed PHP host setup (UI, once): PHP 8.4 site, web root `current/public`, vhost edits (SPA `try_files`, `/api/` `/t/` `/_ops/` → index.php, `/_nuxt/` long cache, `$realpath_root`, anonymised `/t/` log), DB + user, cron jobs, TLS, page cache (Varnish) off for this site.

### 10.3 Docker production (`deploy/docker/compose.prod.yml`)
Images `ghcr.io/manuto276/analytics-php:<version>` (php:8.4-fpm + pdo_mysql, intl, opcache, redis; `validate_timestamps=0`) and `analytics-web` (nginx + public assets), from the same Dockerfile stages. Services: `migrate` (one-shot), `app` (depends on migrate completed, healthcheck), `web` (nginx, anonymised log, behind TLS proxy), `scheduler` (supercronic), `worker` (profile queue), `mysql:8.4` (profile bundled-db), `redis:7` (profile redis, no persistence). Volumes `mysql-data`, `storage`, `logs`; env via `env_file`, no secrets in images. Upgrade: `docker compose pull && up -d`.

## 11. WordPress plugin (optional, generic)
`services/wordpress-plugin/analytics-connector`, GPL-2.0-or-later (own LICENSE). Separate plugin because tracking must load on every public page regardless of theme/page builder, it is versioned with the tracker/API, and it stays optional. Features (< 400 lines): settings page (service URL, public key, direct or proxy path, skip users with a capability); `wp_enqueue_scripts` with `strategy: defer`; `[analytics_consent_link]` shortcode, block and `#analytics-consent` menu link; `analytics_connector_content_key` filter → meta tag; `analytics_connector_track_conversion($name, $args)` (reads `an_vid`, non-blocking `wp_remote_post`, key from `ANALYTICS_CONNECTOR_API_KEY` constant); docs for page caches. Tests: PHPUnit + Brain Monkey; nightly WordPress smoke via Docker.

## 12. `docs/` contents
```
docs/README.md
docs/plan/implementation-plan.md          this document
docs/architecture/ overview.md modules.md data-model.md ingestion.md reporting.md tracker.md
docs/architecture/adr/ 0001-slim-doctrine-dbal-split 0002-additive-rollups-visitor-days 0003-mysql-partitioning
      0004-two-consoles-and-release-layout 0005-static-spa-same-origin 0006-tracker-license 0007-geo-dbip-lite
      0008-separate-wordpress-plugin
docs/privacy/ two-levels.md garante-2021-mapping.md (requirement → implementation → test) data-inventory.md
      cookies.md metric-availability.md legal-review-points.md dpia-inputs.md controller-processor.md
docs/api/ openapi.yaml tracking-payload.v1.schema.json examples/ reporting.md conversions.md errors.md
docs/integration/ tracker.md consent-banner.md spa.md server-side-conversions.md first-party-proxy.md
      caching-proxies.md wordpress.md
docs/deploy/ tarball.md managed-php-hosts.md docker.md nginx.md upgrading.md
docs/operations/ runbook.md cron.md backups.md monitoring.md retention.md key-rotation.md incident-response.md
docs/development/ setup.md testing.md conventions.md i18n.md release.md
docs/CONTRIBUTING.md docs/SECURITY.md docs/CODE_OF_CONDUCT.md
```

## 13. Test strategy

### 13.1 Docker test stack (`compose.base.yml` + `compose.test.yml`)
`mysql` 8.4 (tmpfs, `innodb_flush_log_at_trx_commit=0`, DB per ParaTest worker `analytics_test_{TEST_TOKEN}`); `php` 8.4 CLI/FPM with pcov (+ `php85` for matrix); `nginx` as `analytics.test` with test CA certs; `fixtures` nginx for `www.site.test`, `app.site.test` (SPA + fake backend posting conversions with `an_vid`), `other.test` (forbidden origin), proxy-path variant; `node` 24 + pnpm; `playwright` (pinned image, trusts CA); `redis` profile; `APP_TEST_CLOCK` honoured only in `APP_ENV=test`.

### 13.2 Layers

| Layer | Tooling | Covers |
|---|---|---|
| PHP unit | PHPUnit 13, data providers, FrozenClock | IpTruncator (v4, v6 /48, v4-mapped, invalid); UrlSanitizer; PiiScrubber; Referrer/ChannelClassifier (table-driven golden cases); UtmExtractor; VisitorHasher; PayloadParser (limits, versions, level stripping, age clamp; seeded fuzz); consent version rules; DateRange/Interval/Comparison incl. DST; QueryPlanner; TOTP window/replay; SecretBox round trip + rotation; CustomerRefHasher; AttributionResolver; Authorizer matrix |
| PHP integration (real MySQL) | PHPUnit + DBAL, rollback per test; DDL DB for partitions | repositories; write path (idempotency, 30-min rule, midnight and campaign splits, concurrent requests); concurrent salt creation; rollup builders vs golden raw aggregates + re-run idempotency; `rollup:rebuild` after timezone change; `partitions:maintain` and `retention:purge` (partition drop, chunked delete, refuse dirty days, rollups intact); report snapshots; EXPLAIN assertions; cache invalidation; Redis adapters |
| Migrations | PHPUnit Migration suite | fresh → latest; filtered schema diff empty; partition DDL; `DB_PARTITIONING=false`; contract lint; nightly upgrade from previous tag + seed → HEAD |
| PHP functional HTTP | in-process Slim + real container + test DB; OpenAPI validation of every request/response | `/t/e` origins, text/plain, bad JSON, oversize, unknown version, bot UA, 429, **no Set-Cookie on `/t/*`**; **PrivacyInvariantsTest** (known IP + UA ingested, then scan every column of every table and every log line for full IP/UA → none; IPv6 too); auth (login, lockout, MFA pending cannot reach API, session rotation, logout, idle/absolute expiry, cookie flags, CSRF); **RBAC matrix** from the router × roles (new route without entry fails); API keys; conversions (batch, duplicates, attribution fallbacks); consent publish/versioning; script ETag; SPA fallback |
| Static/quality | PHPStan max (+ extensions, no baseline after M1), Deptrac, PHP-CS-Fixer, Rector dry run, composer validate/audit/require-checker; ESLint, vue-tsc, prettier; actionlint, hadolint, shellcheck | |
| Mutation (nightly) | Infection | Tracking enrichment/payload, Consent, Identity, Shared Net/Crypto: MSI ≥ 80% |
| Tracker unit | Vitest + happy-dom, spies on `document.cookie`, Storage, IndexedDB, sendBeacon, fetch | **no storage before choice**; state machine incl. version bump and 180-d reject expiry; cookie format/domain probe; payload vs JSON schema; SPA; batching/flush; beacon → fetch; DNT/GPC; webdriver skip; queue stub replay; global collision; declarative attributes; banner (shadow root, equal buttons, close/Esc reject, focus return, ARIA, locale) |
| Tracker size | size-limit | ≤ 5.0 KB gzip |
| Tracker real browsers | Playwright Chromium/Firefox/WebKit on fixtures | cookie on `.site.test` visible on `app.site.test`; nothing on `analytics.test`; beacon arrives; axe on banner; keyboard flow; strict-CSP fixture still renders banner |
| Dashboard unit/component | Vitest + @nuxt/test-utils + @vue/test-utils, `registerEndpoint` mocks from OpenAPI examples | useApi (CSRF, 401, 422); useReportQuery ↔ URL; stats deltas/formatting; chart mapping with comparison; SitesMenu; date presets; consent editor contrast + publish dialog; TOTP setup; cost CSV preview; role-based UI; **i18n parity** + no raw text lint |
| Contract | openapi-typescript freshness; same `openapi.yaml` validates backend | |
| End-to-end | Playwright on full test compose; @axe-core/playwright | admin login + TOTP enrolment + re-login; invite viewer → accept → blocked from settings; create site + domains; base visits → `rollup:run` → Overview/Pages/Sources expected counts; realtime; accept on www with UTM → app subdomain same `an_vid` → server conversion → attribution shows campaign; `customer_ref` follow-up within 30 d attributed; funnel counts; cost import → CAC; reject → reload → no banner → version bump → banner again; footer link reopens; forbidden origin rejected; proxy path works; `/t/forget`; retention with frozen clock (+14 months); axe on dashboard (en/it) and banner; screenshot baselines vs template look |
| Deploy tooling | PHPUnit in `deploy/manual/tests` + smoke container imitating a managed PHP host (non-root php-fpm 8.4, nginx with `$realpath_root`, MySQL) | checksum mismatch; path traversal/symlink escape; manifest checks; shared links; atomic switch (concurrent reader never sees missing `current`); failed migration leaves `current`; health failure auto-rollback; keep-N; rollback with DB ahead needs `--force`; lock contention; self-update. Smoke: real tarball → `init` → `deploy` → health → second deploy → `rollback` → `list`/`status`/`cleanup` |
| Docker image | CI job | prod image + `compose.prod.yml` bundled DB → health, one collect, one report |
| Load (nightly, informative) | k6 + `dev:seed` 5M events | collect p95 < 50 ms at 100 rps; reports p95 < 500 ms from rollups; busy-day rollup < 10 s |

Fixtures: builders in `tests/Support/Factory` (Site, User, Invitation, ApiKey, ConsentConfig, EventBatch, Visit, Conversion); `tests/Support/Scenario/SeededDataset` (deterministic 60-day multi-channel, shared by integration, e2e, load); `HttpTestCase` helpers (`loginAs`, `withCsrf`, `collect`, `assertProblem`); tracker `makeConfig`, `installDom`, `advanceTime`; OpenAPI examples reused as mocks and snapshots.

Coverage gates: PHP lines ≥ 85% overall, ≥ 95% Tracking/Consent/Identity/Shared Net+Crypto; tracker lines ≥ 95%, branches ≥ 90%; dashboard composables/utils ≥ 85–90%, components ≥ 70%.

### 13.3 Make targets
`up down logs sh install db-reset seed test test-unit test-integration test-functional test-migrations test-tracker test-tracker-browser test-dashboard test-e2e test-deploy test-smoke test-image coverage mutation perf stan deptrac cs cs-fix rector lint typecheck openapi-types size package ci` (`ci` = exactly what CI runs, via the same compose).

### 13.4 GitHub Actions
- `ci.yml` (PR + push main; buildx + GHA cache): php-quality; php-tests (8.4, 8.5; coverage gate); tracker (lint, test, size); dashboard (lint, typecheck, test, generate, OpenAPI types); deploy-console; then e2e (reports/traces on failure); then package (tarball + smoke) and image.
- `nightly.yml`: mutation, full browser matrix, upgrade-migration test, k6, WordPress plugin smoke, composer/pnpm audit, OWASP ZAP baseline.
- `release.yml` (tag `v*`/manual): tarball + `.sha256` as release assets, GHCR images, SBOM (syft).
- `codeql.yml` (JS/TS), Dependabot (composer, npm, docker, actions).

## 14. Milestones

Each milestone ends with a green `make ci` and a verifiable result.

### M0 — Repository skeleton and tooling
- [x] `git init` in `/Users/emanuelefrascella/Progetti/analytics`, remote `git@github.com:manuto276/analytics.git`, AGPL-3.0 LICENSE, NOTICE, README, `.editorconfig`, `.gitignore`
- [x] Folders `deploy/ services/ docs/`; **save this plan as `docs/plan/implementation-plan.md`**; ADR template
- [x] Dev + test compose (php 8.4 + pcov, nginx + test certs, mysql 8.4, node 24 + pnpm); Makefile
- [x] `services/api`: Slim `GET /api/v1/health`; PHPUnit suites; PHPStan max, Deptrac, CS-Fixer, Rector
- [x] `services/dashboard`: template imported unchanged, `nuxt generate` builds
- [x] `services/tracker`: esbuild + Vitest + size-limit skeleton; `services/e2e` one smoke test
- [x] `ci.yml` for skeleton jobs; `docs/SECURITY.md`, `CONTRIBUTING.md`
- **Verify:** `make ci` green locally and on GitHub; `curl https://analytics.test/api/v1/health` → 200.

### M1 — Backend foundation
- [x] Settings/env, compiled PHP-DI container, ProblemDetails, request id, Monolog without IPs
- [x] ClientIp + truncation middleware, security headers, SameOrigin
- [x] Doctrine ORM/DBAL/Migrations, custom types, schema filter; `bin/analytics` with `migrations:*`, `app:preflight`, `cache:warmup`, `health:check`
- [x] Migration 1 (ORM tables) + Migration 2 (partitioned/DBAL tables); `partitions:maintain`
- [x] Test harness: per-worker DB, factories, FrozenClock, OpenAPI validator; `docs/api/openapi.yaml` v0
- **Verify:** migration suite green (fresh migrate, empty diff); `health:check` exits 0; PHPStan max without baseline.

### M2 — Identity and access
- [x] Users (argon2id), sessions (`__Host-` cookie), CSRF, throttling and lockout, logout
- [x] Roles, Authorizer, SiteAccessMiddleware
- [x] Invitations (copyable link, mailer optional), `user:*` commands, audit log
- **Verify:** functional auth tests and RBAC matrix green; console-created admin logs in via curl.

### M3 — Dashboard shell and site management
- [x] Remove mocks; `useApi`, `useAuth`, auth middleware; auth layout (login, invitation acceptance)
- [x] SitesMenu; Settings → Site (domains, timezone, levels, hash mode + legal notice), Members
- [x] Sites API + `site:create`; snippet endpoint
- [x] i18n en/it with parity test; OpenAPI → TS types; nginx SPA routing + Slim fallback
- **Verify:** e2e: log in, create site with domains, invite and accept viewer (both languages); SPA and API on same origin.

### M4 — Base-level ingestion
- [x] Tracker core: pageview, custom events, engagement, SPA, beacon/fetch, declarative events, no storage
- [x] `/t/{key}.js` (config embed, ETag); `/t/e` (origin check, rate limit, PayloadParser v1 + JSON schema)
- [x] Enrichment: bot filter, UA, URL/PII sanitising, referrer, UTM, channel; `geo:update` + GeoLocator
- [x] DailySaltProvider, VisitorHasher, per-site hash mode; VisitResolver; DBAL sink; dirty marker
- [x] PrivacyInvariantsTest, NoSetCookie test, tracker no-storage test, size budget
- **Verify:** Playwright visits to fixtures create `events_raw` and `visits` rows; privacy tests green; tracker ≤ 5 KB gzip on 3 browsers.

### M5 — Rollups and core reports
- [x] Rollup builders + `rollup:run`/`rollup:rebuild`, locks, job_runs
- [x] ReportQuery, QueryPlanner, ReportCache; overview, timeseries (+ compare), pages, landing-pages, sources, campaigns, tech, countries, events, content, realtime; CSV export
- [x] Dashboard: Overview, Pages, Sources, Campaigns, Audience, Events, Realtime; filters in URL
- [x] Golden tests on seeded dataset + EXPLAIN checks
- **Verify:** golden snapshots match; e2e dashboard shows expected counts after `rollup:run`; visual baselines approved.

### M6 — Consent banner and cookie level
- [x] Consent config draft/publish/revision/version API; editor with live preview and contrast check; history, stats
- [x] Banner (shadow DOM, equal weight, close/Esc reject, a11y, locales), state machine, cookies, JS consent API, reopen link/floating button
- [x] Cookie-level ingestion: vid/sid, `cu`, visitors, attribution_touches, consent_stats_daily, optional receipts, `/t/forget`
- [x] Cohorts report + Retention page
- **Verify:** e2e consent scenarios across `www.site.test`/`app.site.test` (accept, reject, 180-d no re-ask, version bump, reopen, forget); axe clean; `docs/privacy/cookies.md` + `garante-2021-mapping.md` with test ids.

### M7 — Goals, conversions, funnels, attribution, costs
- [x] API keys (scopes, UI, console); conversions API (batch, idempotency, customer_ref HMAC, declared source)
- [x] AttributionResolver (first_touch, last_non_direct, declared) + `conversions:reattribute`
- [x] Goals and funnels CRUD + reports; attribution report (windows, revenue, cost, CAC, ROAS, % unattributed)
- [x] Campaign costs: manual + CSV import with preview
- [x] External read API `content/{key}/stats` with `min_group_size`
- **Verify:** e2e consented visit with UTM → server conversion → follow-up by customer_ref within 30 d → attribution and CAC correct; funnel counts match golden values.

### M8 — Security hardening
- [x] TOTP enrolment, MFA login, recovery codes, session list/revoke, password change/reset (mailer)
- [x] Key rotation, CSP hashes at build, rate-limit review, threat model, `docs/SECURITY.md`
- **Verify:** functional + e2e MFA flows green; security header tests green; ZAP baseline no high findings.

### M9 — Retention and operations
- [x] `retention:purge`, `salt:rotate`, `queue:work` (Redis), `jobs:status`
- [x] JobsSlideover; health details (rollup lag, geo DB age)
- [x] Runbook, cron, backups (exclude salts), monitoring docs
- **Verify:** frozen-clock integration: raw > 13 months removed, rollups intact; queue-mode suite green with Redis.

### M10 — Packaging and deploy tooling
- [x] Dockerfile stages (package, php-runtime, nginx-runtime); `build.sh`, `publish.sh`
- [x] Deploy `console` (init, deploy, verify, list, status, rollback, cleanup, app, self-update) + PHPUnit suite
- [x] Smoke test in CI; `compose.prod.yml` + image test; `release.yml`
- [x] docs/deploy (tarball, managed PHP hosts, docker, nginx with `$realpath_root` + anonymised logs)
- **Verify:** CI builds `analytics-<TS>.tar.gz` + `.sha256` with `REVISION` = HEAD; smoke deploy → health → second deploy → rollback green; prod compose serves a report.

### M11 — WordPress plugin and first production deployment
- [x] Generic `analytics-connector` plugin + tests
- [x] Proxy and cache examples (nginx, Varnish) documented
- [ ] Production: site + DB in hosting panel, web root `current/public`, vhost edits, `.env`, `console init`, first `console deploy`, `user:create-admin`, cron, `geo:update`
- [ ] Legal review: visitor hash mode, subdomains as one site, cookie table, consent texts; enable cookie level only after sign-off
- **Verify:** real base-level pageviews visible within 5 min; `console status` healthy; production rollback drill succeeds; banner live after sign-off.

### M12 (optional) — Scale and polish
- [ ] Load baselines nightly; queue mode above threshold; read-replica config
- [ ] Email reports, country map, automatic events (outbound links, downloads, forms) if confirmed; full dashboard accessibility audit

## 15. Verification checklist (release gate)
- [ ] `make ci` green; coverage and MSI thresholds met
- [x] PrivacyInvariantsTest, NoSetCookie, tracker no-storage-before-choice green
- [x] Tracker ≤ 5.0 KB gzip; axe no serious violations (banner, dashboard)
- [x] OpenAPI types fresh; i18n parity
- [x] Upgrade migration from previous release green; contract lint clean
- [x] Deploy smoke (deploy, rollback) green; checksum matches tarball
- [ ] `docs/privacy/garante-2021-mapping.md` and `cookies.md` updated for any behaviour change

## 16. Risks and open decisions

| # | Topic | Risk / question | Handling |
|---|---|---|---|
| 1 | Service domain | Not chosen; affects CSP, CORS, blocker lists | Everything reads `APP_URL`; neutral name without "analytics/tracking"; proxy path mitigates blockers |
| 2 | Base-level visitor hash | May be treated as fingerprinting | **LEGAL REVIEW**; per-site `pageviews_only`; salt destroyed daily; availability documented |
| 3 | Subdomains as one site | May exceed "single site" statistics | **LEGAL REVIEW**; separate site entries per subdomain possible |
| 4 | Consent proof | No per-person log by default | Immutable versioned configs + counters + cookie; optional receipts; **LEGAL REVIEW** |
| 5 | Mid-page consent attribution | UTM lost if consent on later page | Documented; `share_unattributed` shown; `declared_source` fallback |
| 6 | Controller vs processor | Hosting for third parties makes operator a processor | `controller-processor.md`; DPA template out of scope |
| 7 | GeoIP licensing | DB-IP Lite CC BY 4.0 attribution; accuracy on shortened IPs | Attribution in UI footer/docs; country only; runs without file |
| 8 | MySQL partitioning | No FKs; PK includes partition column; Doctrine diff unaware | DBAL tables + schema filter; raw-SQL migrations; `partitions:maintain`; `DB_PARTITIONING=false` |
| 9 | Static SPA + API on managed PHP host | Default vhost, symlinked root, OPcache without reload, full-IP logs | Documented vhost diff; Slim SPA fallback; opcache reset endpoint; smoke container mirrors setup |
| 10 | CSP with Nuxt inline scripts | Hashes change per build | Generated at build into `config/csp.php`; header test + Playwright CSP violation listener |
| 11 | Page caches on tracked sites | Cookies may split/disable caching | Examples strip `an_*` from cache logic, bypass proxy path |
| 12 | `window.analytics` collision | Clash with other SDKs | Detection + configurable global name |
| 13 | AGPL implications | Source availability for network use; tracker delivered to visitors; WordPress expects GPL-2.0+ | Source link + commit in dashboard and tracker header; plugin GPL-2.0-or-later; ADR 0006 decides tracker AGPL vs MIT; DCO to keep relicensing possible |
| 14 | argon2id availability | Some builds lack it | `app:preflight`; `sodium_crypto_pwhash` fallback |
| 15 | Base visit split at UTC midnight | Small visit overcount | Documented |
| 16 | Small shared VPS (2 cores, ~4 GB) | Rollups/reports compete with other sites | Sync mode at low volume; small batches; `nice`; queue + Redis if needed; nightly load baselines |
| 17 | Mailer undecided | Invitations, password reset | Link invitations + console `user:set-password`; `symfony/mailer` DSN optional |
| 18 | Automatic events scope | Outbound links, downloads, form submits not yet confirmed | Custom events + declarative attributes + engagement in M4; automatic ones in M12 unless confirmed earlier |

## Out-of-repo follow-ups (not part of the public repo)
- The client site that will adopt the service must update its own compliance documents (analytics as a separate self-hosted service, provider row, 13-month raw retention, cookie list) and integrate via the generic plugin; nothing client-specific enters this repo.
