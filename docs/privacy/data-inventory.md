# Data inventory

Every field stored by the service, why it exists and how long it is kept. Table definitions are in
[../architecture/data-model.md](../architecture/data-model.md); this page is the privacy view of the
same schema.

Retention is enforced by `bin/analytics retention:purge` (see [../operations/retention.md](../operations/retention.md)).
`RETENTION_MONTHS` defaults to 13.

## Never stored anywhere

| Data | Why not | Where it is discarded |
|---|---|---|
| Full IP address | not needed; shortened before any use | `ClientIpMiddleware` truncates to /24 (IPv4) or /48 (IPv6) and deletes `REMOTE_ADDR` and every forwarding header from the request |
| Full User-Agent string | only the family and major version are needed | `UserAgentClassifier` returns a classification; the string stays in the local variable of `CollectService::collect()` |
| Shortened IP, on an event | not needed after the hash and the country lookup | never written to `events_raw`, `visits` or the queue |
| Click identifiers (`gclid`, `fbclid`, `msclkid`, …) | cross-site identifiers | `UrlSanitizer::CLICK_IDS`, dropped before storage (their presence is used to classify the channel, then forgotten) |
| E-mail addresses, phone numbers, long digit runs in URLs and event properties | accidental PII in paths and query strings | `PiiScrubber`, applied to paths segment by segment and to query values and property keys/values |
| Query parameters other than the allow list | unbounded, often personal | `UrlSanitizer`; default allow list `utm_source, utm_medium, utm_campaign, utm_content, utm_term, ref` |
| Raw `customer_ref` from the conversions API | must not be readable back | `CustomerRefHasher`: HMAC-SHA-256 with a per-site subkey derived from `APP_SECRET`, stored as `BINARY(32)` |
| Passwords | — | argon2id hash only (`PasswordHasher`, sodium fallback) |
| Session tokens, API key secrets, invitation and reset tokens | — | only SHA-256 hashes are stored; the plaintext is shown once |

The shortened IP **is** stored in two administrative places: `auth_sessions.ip_prefix` and
`audit_log.ip_prefix`, both about signed-in operators, not about visitors.

## Visitor-facing data

### `events_raw` — one row per accepted event

| Field | Why | Level |
|---|---|---|
| `event_uid` (12 random bytes from the browser) | idempotency: a retried beacon must not double-count | both |
| `occurred_at`, `received_at`, `local_day` | time series in the site's time zone; `occurred_at = received_at − clamp(age, 0, 24 h)` | both |
| `level`, `type`, `name` | which level produced the row; `pv`/`ev`/`en`/`cu`/`cs`; custom event name | both |
| `visit_id`, `is_entry` | groups events into a visit; marks an entry pageview (the only visit signal on `pageviews_only` sites) | both |
| `visitor_hash` | count distinct visitors **within one day**; daily-salted, not reversible, not linkable across days | base and cookie, only when `visitor_hash_mode = daily_hash` |
| `visitor_id` | recognises a returning visitor, links conversions | **cookie level only** |
| `host`, `path`, `page_hash`, `query` | which page; sanitised and PII-scrubbed | both |
| `referrer_host`, `channel`, `source`, `utm_*` | where the visit came from; host only, never the full referrer URL | both |
| `browser`, `browser_major`, `os`, `os_major`, `device` | technology breakdown; families and major versions only | both |
| `country` | country breakdown; two letters, from the shortened address | both |
| `content_key` | groups pages by an editorial key the site chooses (`author:42`) | both |
| `engagement_ms`, `scroll_pct` | engaged time and scroll depth of a page | both |
| `props` | up to 10 scalar properties of a custom event, scrubbed, key ≤ 32 bytes, string value ≤ 100 characters | both |

Retention: `RETENTION_MONTHS` (13), by dropping whole monthly partitions.

### `visits` — one row per session

Same dimensions as the entry event, plus `started_at`, `last_activity_at`, `pageviews`, `events`,
`engagement_ms`, `is_bounce`, `entry_*` and `exit_page_hash`. It exists so that visit-level metrics
(bounce rate, duration, entry/exit pages) do not require scanning events. Retention 13 months.

### `visit_lookup` — "which visit is this key currently in"

`visitor_key` (the session id, or the daily visitor hash), `visit_id`, `visit_day`,
`last_activity_at`, `source_key`. Purely operational: it is how the 30-minute inactivity rule is
implemented. Retention 13 months by `visit_day`.

### `visitors` — consented visitors only

`visitor_id`, `first_seen_at`, `last_seen_at`, `first_touch_id`, `visits`, `consent_version`. Exists
for returning-visitor, cohort and attribution reporting. Retention 13 months by `last_seen_at`; erased
immediately by `POST /t/forget`.

### `attribution_touches` — consented visitors only

One row for the visitor's first visit and for every later visit arriving from a non-direct channel:
`touched_at`, `is_first`, `channel`, `source`, `referrer_host`, `utm_*`, `landing_host`,
`landing_path`. It is the evidence a conversion is attributed with. Retention 13 months by
`touched_at`; erased by forget.

### `conversions`

`external_id` (the caller's idempotency key), `name`, `origin`, `occurred_at`, `local_day`,
`visitor_id` (optional), `customer_ref` (HMAC only), `value_minor` + `currency`, `props`,
`declared_source`, and the attribution snapshot (`attr_*` first touch, `lnd_*` last non-direct,
`attr_via`, `attr_model_version`). Retention 13 months. Forget sets `visitor_id = NULL` and keeps the
aggregate.

`declared_source` is a hint supplied by the caller's own systems rather than observed by the tracker;
it is stored separately for exactly that reason — **LEGAL REVIEW**, see
[legal-review-points.md](legal-review-points.md).

### `consent_stats_daily` — counters, no identifiers

`shown`, `accepted`, `rejected`, `dismissed`, `reopened` per `(site, day, consent_version)`. This is
how acceptance rates are measured without recording who decided what. Retention 13 months.

### `consent_stat_uids` — idempotency keys for those counters

`(site_id, local_day, event_uid)`, nothing else. The event uid is the random 12-byte identifier the
tracker generates per event; it carries no information about the visitor or the page and is never
joined to anything. It exists only so a retried beacon cannot inflate the acceptance rate. Partitioned
by month and purged with the same 13-month window as the raw tables.

### `consent_receipts` — optional, off by default

`visitor_id`, `consent_version`, `decision`, `decided_at`, written on a consent upgrade when the site
has `consent_receipts_enabled`. Retention 13 months; erased by forget. A site admin can look a
visitor up with `GET /api/v1/sites/{siteId}/consent/receipts?visitor_id=…` — the visitor supplies the
id (their `an_vid` cookie, or `analytics.getVisitorId()`), so the lookup answers a data-subject
request without exposing anyone else; every lookup is written to the audit log
(`consent.receipts_read`). There is no bulk export.

### `daily_salts`

`day` and 32 random bytes. It is the key of the base-level visitor hash. Older rows are deleted on
every salt read and by `salt:rotate`, so only today's exists. **Excluded from backups** — see
[../operations/backups.md](../operations/backups.md).

### Rollup tables

Aggregates only, keyed by `(site_id, day, …dimensions)`. They contain no identifier: the smallest
grain is a count. `rollup_consented_visitors_monthly` stores monthly distinct counts of consented
visitors — still a count, not a list. Rollups are **kept indefinitely**, which is what allows raw data
to be deleted after 13 months while historic charts stay intact.

## Operator-facing data

| Table | Fields | Why | Retention |
|---|---|---|---|
| `users` | email, argon2id hash, display name, role, locale, status, failed logins, lock, timestamps | dashboard accounts | until deleted |
| `auth_sessions` | sha256 of the token, user, state, CSRF secret, timestamps, `ip_prefix`, `ua_summary` (120 chars) | session management, "your sessions" list, revocation | deleted once expired or revoked |
| `totp_credentials` | encrypted TOTP secret (XChaCha20-Poly1305, key id), confirmation, last used step | MFA, replay protection | until MFA is removed |
| `recovery_codes` | sha256 hashes, `used_at` | MFA recovery | until regenerated |
| `invitations` | email, token hash, roles, expiry | onboarding | 30 days after acceptance or expiry |
| `password_resets` | token hash, expiry | password reset (mailer only) | on expiry |
| `email_changes` | token hash, requested address, expiry, use time | email change confirmation (mailer only) | on expiry |
| `api_keys` | prefix, secret hash, scopes, last used | server API authentication | until revoked and deleted by hand |
| `audit_log` | action, actor, target, `ip_prefix`, redacted metadata (`user.email_changed` keeps the previous address, to investigate a hijacked account) | accountability | 24 months |
| `job_runs` | job, status, timings, stats | operations | 90 days |

`AuditLogger::redact()` replaces any metadata key containing `password`, `secret`, `token`, `code`,
`key`, `totp` or `recovery` with `[redacted]` (except `key_prefix`, `public_key`, `content_key`).

## Third parties

None at runtime. The country database is downloaded by a scheduled command from `db-ip.com` (server
to server, no visitor data). The mailer, if configured, receives invitation and password-reset
messages addressed to operators.

See [controller-processor.md](controller-processor.md) for the roles and
[dpia-inputs.md](dpia-inputs.md) for the assessment inputs.
