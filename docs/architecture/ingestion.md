# Ingestion

From a browser request to rows in `events_raw`. The code path is
`TrackingController` → `CollectService` → `EventSink` → `IngestBatchHandler`.

## Endpoints

| Endpoint | Purpose | Success |
|---|---|---|
| `GET /t/{publicKey}.js` | tracker bundle with the site configuration embedded | `200` `application/javascript`, `ETag`, `Cache-Control: public, max-age=300, stale-while-revalidate=600`, `Access-Control-Allow-Origin: *`; `304` on a matching `If-None-Match`; `404` with a JavaScript comment body for an unknown or archived site |
| `POST /t/e` | event batch | `202` with an empty body, `Cache-Control: no-store` |
| `OPTIONS /t/e`, `OPTIONS /t/forget` | CORS preflight for the `fetch` fallback | `204`, `Access-Control-Allow-Methods: POST, OPTIONS`, `Access-Control-Allow-Headers: Content-Type`, `Access-Control-Max-Age: 86400` |
| `POST /t/forget` | erase the cookie-level data of one visitor id | `202` |

`POST /t/e` returns `202` even when every event is discarded (bot, Do Not Track, excluded path,
foreign host, duplicate). The client is never told what happened to its data — that keeps the endpoint
useless as an oracle and keeps the tracker simple. Only structural problems produce an error: see
[../api/errors.md](../api/errors.md).

CORS: the response echoes the request `Origin` (when it looks like `https?://host`) in
`Access-Control-Allow-Origin` and adds `Vary: Origin`. There is no credentials header — the tracker
sends `credentials: 'omit'`.

## 1. Transport and body

`BodyParsingMiddleware` reads at most **65 536 bytes** (`MAX_BYTES`; CSV uploads on the dashboard API
get 2 MB) and decodes JSON when the content type is `application/json`, `*+json`, or — only under
`/t/` — `text/plain` or absent. That is what makes `navigator.sendBeacon(url, new Blob([json], {type:
'text/plain'}))` work without a preflight. A body over the limit is `413 payload_too_large`;
undecodable JSON is `400 invalid_json`.

## 2. Payload v1

`Analytics\Tracking\Application\Payload\PayloadParser` is a hand-written validator; the normative
schema is [../api/tracking-payload.v1.schema.json](../api/tracking-payload.v1.schema.json) (shared by
the tracker tests and the PHP tests).

```jsonc
{
  "v": 1,
  "k": "pk_XXXXXXXXXXXXXXXXXXXXX",
  "l": "b",                       // b = base, c = consented
  "vid": "…", "sid": "…",         // 22-char base64url, only when l=c
  "cv": 3,                        // consent version the client decided on
  "sw": 1440,                     // screen width in CSS px
  "e": [
    { "id": "…",                  // 16-char base64url = 12 random bytes
      "t": "pv",                  // pv | ev | en | cu | cs
      "u": "https://www.example.com/pricing?utm_source=newsletter",
      "r": "https://mail.example.org/",
      "a": 1234,                  // age in ms
      "n": "signup_click", "p": {"plan": "pro"},   // ev
      "ck": "author:42",                            // content key
      "ms": 15432, "sp": 80,                        // en: engaged ms, scroll %
      "cs": "shown",                                // cs: shown|accept|reject|dismiss|reopen
      "lu": "…", "lr": "…" }                        // cu: landing URL and referrer
  ]
}
```

Limits enforced by the parser:

| Rule | Value |
|---|---|
| Supported versions | `1` (`SUPPORTED_VERSIONS`); anything else → `400 unsupported_version` |
| Events per batch | 50 → `400 too_many_events` |
| URL length | 2048 characters; scheme must be http/https and a host must be present |
| Event name | `/^[a-z0-9_:.-]{1,64}$/` |
| Props | 10 keys, key ≤ 32 bytes, string value ≤ 100 characters, scalars only |
| Content key | `/^[A-Za-z0-9_:.\/-]{1,128}$/` |
| Age `a` | clamped to `[0, 86 400 000]` ms |
| Engagement `ms` | clamped to `[0, 86 400 000]`; `sp` clamped to `[0, 100]` |
| Screen width | `[0, 100000]`, otherwise dropped |

Structural problems reject the whole batch (`invalid_payload`, `unsupported_version`,
`too_many_events`). A single malformed event is dropped silently and counted in
`PayloadParser::$dropped`. Duplicate `id` values inside one batch are dropped too.

A `c` payload with a missing or malformed `vid`/`sid` is **downgraded to base level**, not rejected.

Tests: `Analytics\Tests\Unit\Tracking\PayloadParserTest` (including a seeded fuzz case).

## 3. Site, origin and rate limits (`CollectService::collect`)

1. **Site lookup** by public key through `SiteRepository::snapshotByPublicKey()` (a `SiteSnapshot`
   cached in the application cache). Unknown or archived → `404 unknown_site`.
2. **Origin check** — `CollectService::originAllowed()` takes the host of the `Origin` header, or of
   `Referer` when `Origin` is absent, and requires it to match a `site_domains` row (exact host, or a
   subdomain when `include_subdomains` is set). Localhost is allowed only when the site has
   `allow_localhost`. No usable host → `403 origin_not_allowed`.
3. **Rate limits** — `collect` (300/min per site + shortened IP) and `collect_site` (30 000/min per
   site). Exceeded → `429 rate_limited` with `Retry-After`.

## 4. Enrichment order

All of this happens in memory, once per batch, in `CollectService`. The full User-Agent exists only
inside this call and is never written anywhere.

1. **User-Agent classification** — `UserAgentClassifier` runs CrawlerDetect and then DeviceDetector
   (version truncation: major only) and returns browser family + major, OS family + major, and a
   device class (`desktop`, `mobile`, `tablet`, `other`). When the class is `other` and the payload
   carries `sw`, the width decides (`<768` mobile, `<1024` tablet, else desktop). Results are memoised
   per process by a hash of the UA.
2. **Bot and exclusion filter** — `BotFilter::requestRejection()` drops the batch when the UA is a
   crawler or empty, when `Accept-Language` is empty, or when the shortened IP matches one of the
   site's `excluded_ip_prefixes`. The response is still `202`.
3. **Do Not Track / GPC** — `dnt_mode = no_tracking` with `DNT: 1` drops the batch;
   `dnt_mode = no_cookie` with `DNT: 1`, or `Sec-GPC: 1` with `respect_gpc`, or a site with
   `cookie_level_enabled = false`, downgrades a `c` payload to base level.
4. **Visitor hash** — for `visitor_hash_mode = daily_hash` only:
   `VisitorHasher::hash(site_id, ip_prefix, user_agent, salt_of_today)` =
   `sodium_crypto_generichash(pack('N', siteId) ‖ ip_prefix_packed ‖ "\0" ‖ userAgent, salt, 16)`.
   The first 8 bytes become a `BIGINT UNSIGNED` (`toInt()` masks to 63 bits).
5. **Geo** — `MaxMindGeoLocator::country()` looks up the **shortened** address in the DB-IP Lite
   Country database and returns a two-letter code or `null`. Missing file → always `null`.
6. Then, per event:
   - **URL sanitising** — `UrlSanitizer::sanitize()` lowercases the host, drops the fragment (unless
     `hash_routing`, where a `#/…` fragment becomes part of the path), percent-decodes unreserved
     characters, keeps only allow-listed query parameters, always drops click ids (`gclid`, `gbraid`,
     `wbraid`, `dclid`, `msclkid`, `fbclid`, `ttclid`, `twclid`, `li_fat_id`, `yclid`, `igshid`,
     `mc_eid`, `_hsenc`, `_hsmi`, `epik`, `rdt_cid`, `sccid`, `srsltid`), sorts what is left and caps
     path and query at 1024 characters. The raw parameters are kept in memory for channel detection.
   - **PII scrubbing** — `PiiScrubber` replaces e-mail addresses with `[email]`, runs of 9+ digits
     with `[number]` and phone-like sequences with `[phone]`, in paths (segment by segment), query
     values and event property keys and values.
   - **Host check** — the sanitised host must belong to the site, otherwise the event is dropped.
   - **Path exclusion** — `BotFilter::isExcludedPath()` matches the site's `excluded_paths` globs
     (`fnmatch`, also matching everything under a prefix). Consent statistics (`cs`) are exempt.
   - **Referrer, UTM and channel** — `ChannelClassifier::classify()` derives `utm_*` from the raw
     parameters (falling back to `ref` for the source), resolves the referrer host (own hosts are
     ignored) through `ReferrerClassifier` (`services/api/resources/referrers/*.json`: `search`,
     `social`, `email`, `video`, `ai`), and picks one of `direct`, `organic_search`, `paid_search`,
     `organic_social`, `paid_social`, `email`, `referral`, `campaign`, `internal`. Click ids win: a
     `gclid`/`msclkid`/`dclid`/`gbraid`/`wbraid` makes the visit `paid_search`. Only `pv` and `cu`
     events are classified; everything else is `direct`.
   - **`occurred_at`** = `now − a` (clamped); `local_day` is that instant in the site time zone.

The result is an `EventDraft` — a plain, already anonymised value object. In queue mode this is what
is serialised to Redis, which is why the queue never contains an address or a User-Agent
(`Analytics\Tests\Integration\Tracking\QueueModeTest::testQueuedPayloadsCarryNoIpOrUserAgent`).

## 5. The daily salt

`DailySaltProvider` has two implementations:

- `DbalDailySaltProvider` — `INSERT IGNORE INTO daily_salts (day, salt, created_at)` with 32 random
  bytes, re-reads the row (so concurrent requests converge on one salt), then
  `DELETE FROM daily_salts WHERE day < today`. The salt is memoised per process.
- `RedisDailySaltProvider` — `SET an:salt:<day> <32 bytes> EX 90000 NX`, `EXPIREAT` next UTC
  midnight, then `DEL an:salt:<yesterday>`.

The day is always the **UTC** day. Yesterday's salt is destroyed as soon as today's is used, so a
base-level `visitor_hash` cannot be recomputed or linked across days, even with the raw inputs.
`bin/analytics salt:rotate` (cron, every 15 minutes) enforces the invariant on quiet sites.

Two consequences, both intentional:

- **Base visits split at UTC midnight** for sites whose time zone is not UTC: the visitor key changes,
  so a session crossing UTC midnight is counted as two visits.
- **`daily_salts` must never be backed up.** The deploy console's `mysqldump` excludes it; see
  [../operations/backups.md](../operations/backups.md).

## 6. Visit resolution

`IngestBatchHandler::resolveVisit()`. The visit key (`EventDraft::visitKey()`) is:

| Site mode / level | Visit key |
|---|---|
| Cookie level (`l=c`) | the session id `sid` |
| `daily_hash`, base level | the 16-byte visitor hash |
| `pageviews_only` | none — no visits are created; a pageview that does not come from the site itself is flagged `is_entry = 1` and counted as a visit by the reports |

With a key, the handler reads `visit_lookup … FOR UPDATE` (joined to `visits` to learn the level) and
considers the visit **active** when all of these hold:

- the lookup row exists;
- `visit_day` equals the event's `local_day` (so a visit never spans two local days);
- the event is at most **1800 seconds** (`VISIT_TIMEOUT_SECONDS`) after the last activity.

An active visit is ended early when the site has `new_visit_on_campaign_change` (default on) and a
pageview arrives with a different `source_key` (a hash of channel/source/campaign).

If no visit is active, a new one is started — except for engagement (`en`) events, which never create
a visit. `cu` (consent upgrade) is special: when the new cookie-level key has no active visit but the
base-level visitor hash does, the existing base visit is **upgraded in place** (`level = 'c'`,
`visitor_id` filled) instead of a second visit being created.

Per visit the handler accumulates `pageviews`, `events`, `engagement_ms`, the last activity, the exit
page hash, and recomputes `is_bounce = (pageviews <= 1 AND events = 0)`.

## 7. Levels

**Base (`l=b`)** may carry `visitor_hash`; it never carries `visitor_id`, never writes `visitors`,
`attribution_touches` or `consent_receipts`, and is never linked to a conversion.

**Consented (`l=c`)** carries both. On the first visit of a visitor, and on every later visit whose
entry channel is external (`Channel::isExternal()`: anything but `direct` and `internal`),
`recordConsentedVisit()` upserts `visitors` and appends a row to `attribution_touches`. The first
touch id is stored on the visitor row (`first_touch_id`).

**Mid-page consent.** The `cu` event carries `lu`/`lr` — the landing URL and referrer of the current
page load, kept in the tracker's memory — so a visitor who accepts halfway down the landing page keeps
the original campaign. A visitor who accepts on a *later* page loses the original UTM values: the
touch records that later page. This is documented rather than solved; the attribution report exposes
the unattributed share.

## 8. Write path

`INGEST_MODE=sync` (default) → `SyncEventSink` → `IngestBatchHandler::handle()` inside the request.
One transaction per site batch, `READ COMMITTED`, retried up to 4 times on a deadlock or a unique
violation with a randomised backoff. In order:

1. drop drafts whose `event_uid` already exists for that site and day;
2. resolve visits (`SELECT … FOR UPDATE` on `visit_lookup`);
3. `INSERT IGNORE INTO events_raw` in chunks of 100 rows;
4. update the visit aggregates;
5. upsert `visit_lookup`;
6. increment `consent_stats_daily` counters;
7. `INSERT … ON DUPLICATE KEY UPDATE` into `rollup_dirty` for each touched local day.

`INGEST_MODE=queue` → `RedisQueueEventSink` `RPUSH`es the JSON drafts onto the Redis list `an:ingest`
and the request ends. `bin/analytics queue:work --max-time=55 --batch=500` (cron every minute, or the
long-running `worker` service in Docker) pops them and runs the same `IngestBatchHandler`. Requires
`REDIS_DSN`. Malformed queue entries are dropped.

## 9. Idempotency

`events_raw` has `UNIQUE (site_id, event_uid, local_day)` and rows are written with `INSERT IGNORE`,
after an explicit pre-check that also removes the duplicates from the in-memory batch (so visit
counters are not incremented twice). A client that retries a `sendBeacon` — which the tracker does on
a network error or a 5xx — therefore cannot double-count. Event uids are 12 random bytes generated in
the browser.

Test: `Analytics\Tests\Functional\Tracking\CollectTest::testBatchesAreIdempotent`.

## 10. Consent upgrade and consent statistics

- `cs` events are counters only. They are always sent at base level, never create or join a visit, are
  exempt from the path exclusion list and from the "base tracking disabled" switch, and land in
  `consent_stats_daily` keyed by `(site_id, day, consent_version)`.
- `cu` is sent once, right after an "accept", at cookie level. It upgrades the visit, creates the
  visitor and the first touch, and — when `consent_receipts_enabled` — writes a `consent_receipts`
  row.

## 11. Forget

`POST /t/forget` with `{"k": "pk_…", "vid": "<22 chars>"}`. The origin check and the `public` rate
limit (60/min per site + shortened IP) apply. `ForgetService::forget()` runs one transaction:

```
DELETE FROM events_raw          WHERE site_id = ? AND visitor_id = ? AND level = 'c'
DELETE l FROM visit_lookup l JOIN visits v … WHERE v.site_id = ? AND v.visitor_id = ?
DELETE FROM visits              WHERE site_id = ? AND visitor_id = ?
DELETE FROM attribution_touches WHERE site_id = ? AND visitor_id = ?
DELETE FROM visitors            WHERE site_id = ? AND visitor_id = ?
DELETE FROM consent_receipts    WHERE site_id = ? AND visitor_id = ?
UPDATE conversions SET visitor_id = NULL WHERE site_id = ? AND visitor_id = ?
```

and marks the affected days dirty so the rollups are rebuilt without the deleted rows. Conversions are
kept but detached: the aggregate revenue stays correct while the link to a person is gone. Base-level
rows are not touched — they are not linked to the visitor id in the first place.

The tracker calls this from `analytics.consent.forget()`, which also deletes the cookies and moves the
state to "rejected".

Test: `Analytics\Tests\Functional\Tracking\CollectTest::testForgetErasesCookieLevelDataOnly`.

## 12. Tracker script endpoint

`ScriptBundleBuilder::build()` composes:

```
/*! analytics | AGPL-3.0-or-later | source: <SOURCE_URL> */
window.__an_cfg={…};
<contents of services/api/resources/tracker/tracker.js>
```

The `ETag` is a SHA-256 over the configuration JSON and the tracker file hash, so publishing a consent
revision or changing a site setting changes the `ETag` immediately; the built bundle is cached for an
hour in the application cache and the published consent block for 60 seconds. When the tracker file is
missing (a checkout where `pnpm build:api` has not run) the endpoint serves a harmless no-op stub.

The configuration keys are documented in [tracker.md](tracker.md).

## See also

- [../api/errors.md](../api/errors.md) — every error code the endpoints return
- [../privacy/data-inventory.md](../privacy/data-inventory.md) — what each stored column is for
- [reporting.md](reporting.md) — what happens to the rows afterwards
