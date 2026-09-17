# The two tracking levels

Every site runs at one of two levels at any moment. The level is decided in the visitor's browser and
sent as `l` in the payload; the server verifies and can only downgrade it, never upgrade it.

| | Base level (`l=b`) | Cookie level (`l=c`) |
|---|---|---|
| When | always, unless the site or the visitor turns it off | only after an explicit "Accept" |
| Consent needed | no (subject to [LEGAL REVIEW](legal-review-points.md)) | yes |
| Storage in the browser | none at all | `an_consent`, `an_vid`, `an_sid` |
| Identifier | `visitor_hash` — a daily-rotating salted hash, or nothing | `visitor_id` (random 128 bits) plus the daily hash |
| Identity across days | impossible: the salt is destroyed | yes, for `visitor_cookie_days` (≤ 395) |
| Written to | `events_raw`, `visits`, `visit_lookup`, `consent_stats_daily`, rollups | additionally `visitors`, `attribution_touches`, optionally `consent_receipts` |
| Linked to conversions | never | yes, through `visitor_id` |
| Cohorts / retention | not available | available |

## Base level

The tracker sends pageviews, custom events and engagement without touching cookies, `localStorage`,
`sessionStorage` or IndexedDB. Before the visitor has made a choice it **reads** `an_consent` and
nothing else — a read of a cookie that does not exist writes nothing.

Server-side, the batch is enriched in memory and the only identifier that can be stored is the
`visitor_hash`, and only when the site's `visitor_hash_mode` is `daily_hash`:

```
sodium_crypto_generichash(pack('N', siteId) ‖ ip_prefix ‖ "\0" ‖ userAgent, salt_of_today, 16)
```

- `ip_prefix` is the shortened address (IPv4 /24, IPv6 /48); the full address never exists past the
  outermost middleware.
- `userAgent` is used only inside this call; the classification result (browser family + major, OS
  family + major, device class) is what gets stored.
- `salt_of_today` is 32 random bytes that exist for one UTC day and are deleted at rotation. Yesterday
  is not recoverable even with the full inputs and the database.

Consequences of the daily salt:

- "Visitors" over a range are **visitor-days**, not people ([metric-availability.md](metric-availability.md));
- a session that crosses UTC midnight is counted as two visits on a site whose time zone is not UTC;
- there is no way to recognise a returning visitor, by design.

The second mode, `visitor_hash_mode = pageviews_only`, stores no hash at all. There are then no
visits, no bounce rate and no duration; visits are estimated from entry pageviews. Use it when the
daily hash is considered too identifying — see [legal-review-points.md](legal-review-points.md).

Base level is disabled entirely per site with `base_tracking_enabled = false`. Consent statistics
(`cs` events) are still accepted, because they are pure counters.

## Cookie level

Reached only after the visitor clicks "Accept" in the banner. The tracker then writes `an_vid` (the
visitor id) and `an_sid` (the session id) as first-party cookies on the tracked site, and sends them
with every batch. See [cookies.md](cookies.md).

What the cookie level adds:

- a stable `visitor_id`, so returning visits, cohorts and retention become possible;
- `attribution_touches` — the first visit and every later visit arriving from a non-direct channel —
  which is what lets a server-side conversion be attributed to a campaign;
- `consent_receipts`, optionally, one row per acceptance.

A consent upgrade (`cu`) carries the landing URL and referrer of the current page load, so accepting
halfway down the landing page keeps the original campaign. Accepting on a later page does not: the
touch records that later page. The attribution report shows the unattributed share so the effect is
visible.

## How a level is downgraded

`CollectService::collect()` forces `l=c` down to `l=b` when any of these hold:

| Condition | Effect |
|---|---|
| the site has `cookie_level_enabled = false` | downgrade |
| `DNT: 1` and the site's `dnt_mode = no_cookie` | downgrade |
| `Sec-GPC: 1` and the site's `respect_gpc` (default true) | downgrade |
| `DNT: 1` and `dnt_mode = no_tracking` | the whole batch is dropped |
| the payload has no valid `vid`/`sid` | downgrade (in the parser) |

The tracker applies the same rules client-side, so under GPC or `no_cookie` it never shows the banner
and never writes a cookie in the first place.

## Withdrawal

- **Reject** (button, Close, Escape): `an_consent` is written with `r`, `an_vid` and `an_sid` are
  deleted on the host and on every candidate domain, and the level drops to base. Nothing already
  stored is deleted.
- **`analytics.consent.forget()`**: additionally calls `POST /t/forget`, which erases every
  cookie-level row of that visitor id server-side and detaches their conversions. See
  [../architecture/ingestion.md](../architecture/ingestion.md).

A rejection is remembered for `rejected_ttl_days` (default 180) and the banner is not shown again
until it expires or the published `consent_version` increases.

## What never happens at either level

- No full IP address and no full User-Agent is stored, queued or logged
  (`PrivacyInvariantsTest::testNoFullAddressOrUserAgentAnywhere`).
- The service domain never sets a cookie: `/t/*` responses have `Set-Cookie` stripped
  (`CollectTest::testTrackingEndpointsNeverSetOrReadCookies`).
- Base-level rows are never joined to a `visitor_id`, to a conversion or to anything external.
- No endpoint returns an individual visitor, event or conversion row.

See also [data-inventory.md](data-inventory.md), [cookies.md](cookies.md),
[garante-2021-mapping.md](garante-2021-mapping.md).
