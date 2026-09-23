# Server-side conversions API

A customer backend reports a conversion — an order, a signup, a qualified lead — to the analytics
service, which attributes it to the campaign that brought the visitor. It is a server-to-server API
authenticated with an API key, not with a session.

Endpoint:

```
POST /api/v1/server/sites/{publicKey}/conversions
```

`{publicKey}` is the site's public key (`pk_` + 21 alphanumerics), the same one in the tracker
snippet. It is not a secret.

## API keys

Format: `ak_<prefix>_<secret>` — an 8-character alphanumeric prefix and a 43-character base64url
secret (32 random bytes). The prefix identifies the row in `api_keys`; only a SHA-256 of the secret is
stored, so **the key is shown once and cannot be recovered**.

Create one from the console or from the dashboard:

```sh
php bin/analytics api-key:create --site=pk_XXXXXXXXXXXXXXXXXXXXX --name=shop --scopes=conversions:write
# or: --site=1
```

or `POST /api/v1/sites/{siteId}/api-keys` (permission `site:manage`), listed with
`GET …/api-keys` and revoked with `DELETE …/api-keys/{keyId}` or
`php bin/analytics api-key:revoke <prefix>`.

| Scope | Grants |
|---|---|
| `conversions:write` | `POST …/conversions` |
| `stats:read` | `GET …/content/{contentKey}/stats` |
| `reports:read` | `GET …/reports/{report}` — the dashboard's reports, see [reporting.md](reporting.md#with-an-api-key-server-to-server) |

A key belongs to exactly one site. Using it against another site's public key gives `404 not_found` —
the same answer as a key that does not exist, so keys cannot be used to enumerate sites. A key may
have an `expires_at`; after it, and after revocation, it answers `401 unauthorized`.

Send it as a bearer token:

```
Authorization: Bearer ak_1a2b3c4d_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Rate limit: 1200 requests per minute per key prefix (`server` policy).

Keep the key on the server. It must never reach a browser: it can write conversions for the site.

## Request body

One object, or an array of at most 100 objects.

```json
{
  "id": "order-8812",
  "name": "purchase",
  "occurred_at": "2026-09-17T10:00:00Z",
  "visitor_id": "AbCdEfGhIjKlMnOpQr_-12",
  "customer_ref": "opaque-customer-123",
  "value": { "amount_minor": 4900, "currency": "EUR" },
  "props": { "plan": "pro" },
  "declared_source": { "utm_source": "newsletter", "utm_medium": "email", "utm_campaign": "september", "channel": "email" }
}
```

| Field | Required | Rules |
|---|---|---|
| `id` | yes | ≤ 128 characters. The idempotency key, unique per site |
| `name` | yes | `^[a-z0-9_:.-]{1,64}$` |
| `occurred_at` | no | any string `DateTimeImmutable` accepts, normalised to UTC. Not more than 1 hour in the future, not more than 30 days in the past. Default: now |
| `visitor_id` | no | exactly the `an_vid` cookie value: 22 base64url characters decoding to 16 bytes |
| `customer_ref` | no | ≤ 256 characters, trimmed. **Stored only as an HMAC-SHA-256** with a per-site subkey derived from `APP_SECRET`; it cannot be read back |
| `value.amount_minor` | no | integer in minor units, −10¹² … 10¹². `value.currency` is required when `value` is present |
| `value.currency` | with `value` | exactly three uppercase letters (`^[A-Z]{3}$`) |
| `props` | no | at most 10 scalar properties, key ≤ 32 bytes, string value ≤ 100 characters |
| `declared_source` | no | any of `utm_source`, `utm_medium`, `utm_campaign`, `channel`, each ≤ 100 characters. Stored separately from the observed attribution — see LR-4 in [../privacy/legal-review-points.md](../privacy/legal-review-points.md) |

`local_day` is derived from `occurred_at` in the site's time zone, `origin` is always `server`, and the
day is marked for a rollup rebuild.

Revenue is only summed into a report when `currency` equals the site's currency; conversions in other
currencies are counted but contribute 0 to `revenue_minor`.

## Response

Always `202 Accepted` when the request itself is well-formed:

```json
{ "accepted": 1, "duplicates": 0, "rejected": [] }
```

- `accepted` — rows inserted.
- `duplicates` — rows whose `(site_id, id)` already existed. Not an error: this is the idempotency
  guarantee at work.
- `rejected` — per-item failures, `[{ "index": 2, "error": "id: This field is required." }]`, where `index`
  is the position in the submitted array. A partially valid batch is accepted in part.

Request-level failures are problem documents: `400 invalid_json`, `400 empty_batch`,
`400 too_many_conversions`, `401 unauthorized`, `403 forbidden` (wrong scope), `404 not_found`
(unknown site or a key belonging to another site), `413 payload_too_large`, `429 rate_limited`. See
[errors.md](errors.md).

## Idempotency

`conversions` has `UNIQUE (site_id, external_id)` and rows are written with `INSERT IGNORE`. Sending
the same `id` again is safe and reports it under `duplicates`; it never updates the existing row.
Always use a stable business identifier (the order number), never a random one, so retries — including
the WordPress plugin's non-blocking request, whose outcome the caller cannot observe — are harmless.

## Attribution

`AttributionResolver::resolve()` runs at insert time and snapshots the result on the row together with
`attr_model_version` (currently 1) and `attr_via`.

1. **Visitor touches** (`attr_via = "visitor"`). When `visitor_id` is given and that visitor has
   `attribution_touches` at or before `occurred_at`, the row stores both:
   - `attr_*` — the visitor's **first** touch (`first_touch` model);
   - `lnd_*` — their **last non-direct** touch before the conversion (`last_non_direct` model),
     excluding the `direct` and `internal` channels.
2. **Same customer** (`attr_via = "customer_ref"`). Otherwise, when `customer_ref` is given, the
   attribution of the earliest already-attributed conversion with the same keyed hash is copied. That
   is how a follow-up order from a customer who converted on a different device inherits the original
   campaign.
3. **Unattributed** (`attr_via = "none"`). Otherwise `attr_channel` and `lnd_channel` are
   `"unattributed"`. The conversion is still counted; the attribution report shows the unattributed
   share.

`declared_source` is never used by 1–3. It is only read when the attribution report is requested with
`model=declared`.

Attribution is a snapshot, not a live join: touches recorded *after* the conversion do not change it.
Recompute with

```sh
php bin/analytics conversions:reattribute --site=pk_XXXXXXXXXXXXXXXXXXXXX [--from=2026-08-01]
```

The attribution report additionally applies a **window** (`window=7|30|90`, default 30): a touch older
than that many days before the conversion is treated as no touch.

## Getting the visitor id

`visitor_id` is the `an_vid` cookie, which exists only after the visitor accepted cookies, and only on
the registrable domain the tracker wrote it to.

- **Same registrable domain** — the backend reads the cookie directly:

  ```php
  $visitorId = $_COOKIE['an_vid'] ?? null;
  if ($visitorId !== null && preg_match('/^[A-Za-z0-9_-]{22}$/', $visitorId) !== 1) {
      $visitorId = null;
  }
  ```

- **Different domain, or a headless checkout** — read it in the page with
  `window.analytics.getVisitorId()` (returns `null` without consent) and send it along, or put an
  `<input type="hidden" data-analytics-visitor>` in the form: the tracker fills it in after consent
  and clears it on rejection.

Without a visitor id the conversion is still recorded, and can still be attributed through
`customer_ref`. See [../integration/server-side-conversions.md](../integration/server-side-conversions.md).

## Examples

### One conversion

```sh
curl -s -X POST \
  "https://stats.example.net/api/v1/server/sites/pk_XXXXXXXXXXXXXXXXXXXXX/conversions" \
  -H "Authorization: Bearer $ANALYTICS_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '{
        "id": "order-8812",
        "name": "purchase",
        "occurred_at": "2026-09-17T10:00:00Z",
        "visitor_id": "AbCdEfGhIjKlMnOpQr_-12",
        "customer_ref": "customer-42",
        "value": { "amount_minor": 4900, "currency": "EUR" },
        "props": { "plan": "pro" }
      }'
```

```json
{ "accepted": 1, "duplicates": 0, "rejected": [] }
```

### A batch with a rejected item

```sh
curl -s -X POST "$URL" -H "Authorization: Bearer $ANALYTICS_API_KEY" \
  -H 'Content-Type: application/json' \
  -d '[{"id":"order-1","name":"purchase"},
       {"id":"order-2","name":"signup"},
       {"id":"order-3","name":"NOT VALID"}]'
```

```json
{ "accepted": 2, "duplicates": 0,
  "rejected": [ { "index": 2, "error": "name: Has an invalid format." } ] }
```

Request and response bodies as files:
[examples/conversion-request.json](examples/conversion-request.json),
[examples/conversion-batch-response.json](examples/conversion-batch-response.json).

## Content stats

```
GET /api/v1/server/sites/{publicKey}/content/{contentKey}/stats?days=30
```

Scope `stats:read`. `days` is 1–395, `contentKey` at most 128 characters. It reads
`rollup_content_daily`, so it reflects the last `rollup:run`.

```json
{
  "data": {
    "content_key": "author:42",
    "days": 30,
    "from": "2026-08-19",
    "to": "2026-09-17",
    "pageviews": 1840,
    "visitors": 1204,
    "contacts": 17,
    "suppressed": false,
    "channels": { "organic_search": 700, "direct": 410, "referral": 94 }
  }
}
```

When the group is smaller than the site's `min_group_size` (default 5) but not empty, `pageviews`,
`visitors`, `contacts` and `channels` are `null`/`{}` and `suppressed` is `true`. The group size is
the visitor count; on a site that stores no visitor hash at all (`visitor_hash_mode` is
`pageviews_only`, or only base-level traffic) that count is always zero, so the visit count is used
instead, and the pageview count when there are no visits either. `contacts` counts events whose name
is in the site's `content_contact_events` list.

The content key is set on a page by `<meta name="analytics:content" content="author:42">` or
`analytics.setContent('author:42')`.

## Reading reports from a server

There is none: the reporting endpoints are session-authenticated. The only server-to-server read is
the content stats endpoint above. For anything else, export CSV from the dashboard or query the
database directly.
