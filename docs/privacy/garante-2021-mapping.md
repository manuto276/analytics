# Garante 2021 cookie guidelines — requirement mapping

The Italian Garante's guidelines on cookies and other tracking tools of 10 June 2021 allow
*analytics* to be used **without consent** only under a set of conditions. This page maps each
condition to the implementation and to the test that pins it down.

It is a technical mapping, not legal advice. The points that cannot be settled by code are collected
in [legal-review-points.md](legal-review-points.md) — in particular whether the base-level
`visitor_hash` is acceptable at all, and whether several subdomains may be treated as one site.

Test identifiers below are real classes and methods in this repository:

- PHP: `services/api/tests/**` (`Analytics\Tests\…`)
- Tracker: `services/tracker/test/*.test.ts`, quoted as *file* → "test name"

## A. Conditions for consent-exempt analytics

### A1. The tool must produce aggregate statistics only

| | |
|---|---|
| Implementation | Every report goes through `ReportService::run()` and returns grouped rows. No endpoint returns a row of `events_raw`, `visits`, `visitors`, `attribution_touches` or `conversions`. The external content API suppresses any group below the site's `min_group_size` (default 5). |
| Tests | `Analytics\Tests\Functional\Reporting\ReportsTest::testPagesSourcesTechCountriesEventsContent`, `::testOverviewAndTimeseries`; `Analytics\Tests\Functional\Conversions\ServerConversionsTest::testContentStatsRespectMinimumGroupSize`; `Analytics\Tests\Functional\Identity\RbacMatrixTest::testEverySessionRouteIsDocumentedInOpenApi` (a new route must be declared, so an accidental row-level endpoint is visible in review) |

### A2. The IP address must be shortened before use

| | |
|---|---|
| Implementation | `ClientIpMiddleware` is the outermost middleware. It resolves the address (`ClientIpResolver`, honouring `X-Forwarded-For` only from `TRUSTED_PROXIES`), truncates it (`IpTruncator`: IPv4 → /24, IPv6 → /48, IPv4-mapped IPv6 treated as IPv4) and then removes `REMOTE_ADDR` and every forwarding header from the request. Only the shortened value reaches the hash and the country lookup; it is never stored on an event. `IpScrubbingProcessor` masks addresses in Monolog output, and the nginx examples log `/t/` without `$remote_addr`. |
| Tests | `Analytics\Tests\Unit\Shared\IpTruncatorTest::testTruncate`, `::testPrefixNeverContainsHostBits`, `::testMatchesCidrs`, `::testMaskPacked`; `Analytics\Tests\Unit\Shared\ClientIpResolverTest::testMiddlewareExposesOnlyPrefixAndScrubsFullAddress`, `::testIgnoresForwardedHeaderFromUntrustedPeer`, `::testUsesRightmostUntrustedHop`; `Analytics\Tests\Unit\Shared\LogScrubbingTest::testMasksIpv4AndIpv6`; `Analytics\Tests\Functional\Tracking\PrivacyInvariantsTest::testNoFullAddressOrUserAgentAnywhere` |

### A3. The tool must not be used to combine data across sites, or with data from other sources

| | |
|---|---|
| Implementation | Every identifier is scoped to one site: the visitor hash includes `site_id` in the hashed input, `an_vid` is a first-party cookie of the tracked site and `visitors` is keyed `(site_id, visitor_id)`. There is no cross-site identifier, no third-party cookie and no data import that could be joined to a visitor. The service domain sets no cookie at all, so it cannot recognise a browser across the sites it serves. A conversion may only be linked through a `visitor_id` of the same site or through a per-site HMAC of `customer_ref`. |
| Tests | `Analytics\Tests\Functional\Tracking\CollectTest::testVisitorHashDependsOnNetworkUserAgentAndDay` (the hash changes with the site, the network, the UA and the day); `::testTrackingEndpointsNeverSetOrReadCookies`; `::testOriginIsEnforced` (a site only accepts its own hosts); `Analytics\Tests\Functional\Conversions\ServerConversionsTest::testAttributionFromVisitorTouchesAndCustomerRef` |

### A4. No persistent identifier of the visitor

| | |
|---|---|
| Implementation | At base level nothing is written to the browser at all. Server-side the only identifier is the daily `visitor_hash`, keyed with a salt that lives for one UTC day: `DbalDailySaltProvider`/`RedisDailySaltProvider` delete every earlier salt as soon as today's is used, and `salt:rotate` (cron, every 15 minutes) enforces it. Once deleted, yesterday's hash cannot be recomputed from the same inputs. `visitor_hash_mode = pageviews_only` removes the hash entirely. |
| Tests | `CollectTest::testVisitorHashDependsOnNetworkUserAgentAndDay`; `CollectTest::testPageviewsOnlyModeStoresNoHashOrVisit`; `Analytics\Tests\Unit\Tracking\EnrichmentTest::testVisitorHasher`; `Analytics\Tests\Integration\Tracking\QueueModeTest::testSaltLivesInRedisWhenConfigured`; tracker `test/storage.test.ts` → "never touches cookies, Web Storage or IndexedDB until the visitor decides" |

### A5. The tool must be first-party / under the site operator's control

| | |
|---|---|
| Implementation | The service is self-hosted. No request leaves the operator's infrastructure at runtime: the country database is a local file refreshed by a scheduled server-to-server download. `/t/*` sets no cookie, so the service domain is not a third-party tracker. An optional first-party proxy (`/stats/` on the tracked site) makes even the network request same-origin. |
| Tests | `CollectTest::testTrackingEndpointsNeverSetOrReadCookies`; `Analytics\Tests\Functional\Tracking\ScriptTest::testServesTrackerWithSiteConfigAndEtag` (no `Set-Cookie`, cross-origin resource policy); e2e fixture `services/e2e/fixtures/proxy.site.test` |

## B. Conditions for the consent-based level

### B1. Nothing may be stored before a free, informed, unambiguous choice

| | |
|---|---|
| Implementation | Before a decision the tracker only *reads* `an_consent`. `an_vid`/`an_sid` are written exclusively inside `decide('accepted', …)`. No Web Storage or IndexedDB is used at any point. |
| Tests | tracker `test/storage.test.ts` → "never touches cookies, Web Storage or IndexedDB until the visitor decides"; `test/consent.test.ts` → "starts unknown without a cookie: banner shown, cs shown sent at base level", → "accept writes consent + ids, sends cs accept and cu with landing data" |

### B2. Accept and reject must be equally easy and equally prominent

| | |
|---|---|
| Implementation | The banner renders both as native `<button>` elements with the same class (`k`) and therefore identical styling (`services/tracker/src/banner/template.ts`). Closing (`×`) and Escape are recorded as `dismiss` and are treated as a **rejection**, not as acceptance. Scrolling is not consent — there is no scroll listener in the consent path. There is no blocking overlay. |
| Tests | tracker `test/banner.test.ts` → "renders Accept and Reject as native buttons with identical styling"; `test/consent.test.ts` → "close button and Escape count as dismiss and reject" |

### B3. The choice must be as easy to withdraw as to give

| | |
|---|---|
| Implementation | `analytics.consent.open()` reopens the banner; `[data-analytics-consent]` and links to `#analytics-consent` do it declaratively; an optional floating button (`show_floating_reopen`) is shown after a choice. `analytics.consent.set('rejected')` withdraws directly, and `analytics.consent.forget()` additionally erases the stored data. |
| Tests | tracker `test/api.test.ts` → "[data-analytics-consent] and #analytics-consent links reopen preferences"; `test/banner.test.ts` → "opens via consent.open(), focuses the dialog and returns focus on close", → "shows a floating reopen button after a choice when configured"; `test/consent.test.ts` → "forget sends /forget, deletes ids and rejects" |

### B4. A rejection must not be re-asked immediately

| | |
|---|---|
| Implementation | A rejection is stored for `rejected_ttl_days` (default 180) and the banner is not shown again until it expires. It **is** shown again when the published `consent_version` increases, which happens only when an operator publishes with `material_change`. |
| Tests | tracker `test/consent.test.ts` → "rejection is remembered for 180 days, then asked again", → "a newer published consent version resets the state to unknown", → "a cookie version above the published one is still valid", → "accepted consent expires after the accepted TTL"; `Analytics\Tests\Functional\Consent\ConsentApiTest::testDraftPublishVersioningAndHistory` |

### B5. Consent must be demonstrable

| | |
|---|---|
| Implementation | Published consent configurations are immutable revisions (`consent_configs`, `status` becomes `archived` when superseded) with `published_at` and `published_by`, and every publish is written to `audit_log` (`consent.published`). Per-day counters of `shown`/`accepted`/`rejected`/`dismissed`/`reopened` per version are stored in `consent_stats_daily`. The visitor's own cookie records the version they agreed to. Optional per-visitor receipts exist behind `consent_receipts_enabled`. There is **no** per-person consent log by default. Each counter is incremented once per event uid (`consent_stat_uids`), so a retried beacon cannot inflate the acceptance rate the counters are meant to evidence; those uids carry nothing about the visitor and are purged on the same 13-month window. |
| Tests | `ConsentApiTest::testDraftPublishVersioningAndHistory`; `Analytics\Tests\Functional\Reporting\ReportsTest::testRealtimeConsentAndCohorts` (the consent report); `CollectTest::testConsentedFlowUpgradesVisitAndRecordsTouches`; `CollectTest::testConsentStatisticsAreCountedOncePerEventUid` |
| Open point | whether counters plus the cookie are sufficient proof — **LEGAL REVIEW**, see [legal-review-points.md](legal-review-points.md) |

### B6. The information must be available in the visitor's language, and accessible

| | |
|---|---|
| Implementation | Texts are stored per locale; the banner picks `<html lang>`, falls back to the configured default locale and then to the first available one. It renders in an open shadow root with `role="dialog"`, `aria-modal="false"`, `aria-labelledby`/`aria-describedby`, a focusable dialog, focus return on close, `prefers-reduced-motion` and `forced-colors` support. The editor refuses a theme whose text/background or button contrast is below 4.5:1 (`ContrastChecker`). |
| Tests | tracker `test/banner.test.ts` → "exposes dialog semantics with labelled and described content", → "falls back to the first locale when the default one is missing", → "renders in an open shadow root prepended to body with constructable styles", → "uses theme colors and radius, with motion and forced-colors support"; `Analytics\Tests\Functional\Consent\ConsentApiTest::testValidationAndContrast` |

## C. Related obligations

### C1. Honour browser-level signals

| | |
|---|---|
| Implementation | Per site: `dnt_mode` = `ignore` \| `no_cookie` (DNT:1 behaves like a rejection) \| `no_tracking` (DNT:1 sends nothing), and `respect_gpc` (default on), which treats `Sec-GPC: 1` / `navigator.globalPrivacyControl` as a rejection — no banner, base level only, stored ids deleted. Enforced both in the tracker and again server-side. |
| Tests | tracker `test/skips.test.ts` → "DNT no_tracking with DNT=1 sends nothing", → "DNT no_cookie with DNT=1 behaves like reject", → "GPC means reject: no banner, base only, stored ids removed", → "GPC is ignored when the site does not respect it"; `CollectTest::testDoNotTrackModes`, `::testConsentedBatchIsDowngradedWhenCookieLevelIsOffOrGpc` |

### C2. Data minimisation on what is collected

| | |
|---|---|
| Implementation | `UrlSanitizer` keeps only allow-listed query parameters and always drops click identifiers; `PiiScrubber` removes e-mail addresses, phone numbers and long digit runs from paths, query values and event properties; the referrer is reduced to a host; the User-Agent to family + major version. |
| Tests | `Analytics\Tests\Unit\Tracking\EnrichmentTest::testUrlSanitizer`, `::testPiiScrubber`, `::testUtmValuesAreNormalisedAndScrubbed`, `::testUserAgentClassifier`, `::testExcludedPaths`; `CollectTest::testBasePageviewIsStoredAnonymously` (asserts `gclid` is dropped) |

### C3. Erasure on request

| | |
|---|---|
| Implementation | `POST /t/forget` (and `analytics.consent.forget()`) deletes the visitor's cookie-level events, visits, lookups, touches, visitor row and receipts, detaches their conversions, and marks the affected days for a rollup rebuild. Base-level rows are not linked to the person and stay aggregated. |
| Tests | `CollectTest::testForgetErasesCookieLevelDataOnly`; tracker `test/consent.test.ts` → "forget sends /forget, deletes ids and rejects", → "forget without a visitor id does not call /forget" |

### C4. Storage limitation

| | |
|---|---|
| Implementation | Raw events, visits, visitors, touches, conversions and receipts are removed after `RETENTION_MONTHS` (13 by default); rollups are aggregate and kept. `retention:purge` refuses to delete a day that has not been rolled up yet. |
| Tests | `Analytics\Tests\Integration\Retention\RetentionTest::testPurgeRemovesOldRawDataButKeepsRollups`, `::testPurgeRefusesWhenDaysStillNeedARollup`, `::testOperationalTablesHaveTheirOwnWindows` |

### C5. Security of processing

See [../SECURITY.md](../SECURITY.md). Argon2id passwords, hashed session tokens, `__Host-` cookie,
CSRF, same-origin checks, progressive lockout, rate limits, scoped API keys, encrypted TOTP secrets,
audit log, strict security headers.

Tests: `Analytics\Tests\Functional\Identity\AuthTest` (all methods),
`Analytics\Tests\Functional\Identity\RbacMatrixTest::testMatrix`,
`Analytics\Tests\Unit\Shared\SecretBoxTest`, `Analytics\Tests\Unit\Identity\PasswordHasherTest`.

## Where the mapping stops

- Whether the daily `visitor_hash` is "not an identifier" for the purposes of the guidelines.
- Whether several subdomains configured as one site still count as "a single site".
- Whether counters plus the visitor's cookie are adequate proof of consent.
- Whether `declared_source` on a conversion is compatible with consent-exempt analytics.

All four are marked **LEGAL REVIEW** in [legal-review-points.md](legal-review-points.md). Enable the
cookie level in production only after a sign-off on them.
