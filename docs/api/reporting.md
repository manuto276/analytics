# Reporting API

Read-only, aggregate-only endpoints under `/api/v1/sites/{siteId}/reports/…`. The behaviour behind
them is described in [../architecture/reporting.md](../architecture/reporting.md); this page is how to
call them. The machine-readable contract is [openapi.yaml](openapi.yaml).

## Authentication

These are dashboard routes: an httpOnly session cookie plus, for non-GET requests, an `X-CSRF-Token`
header. Reports are all `GET`, so a session cookie is enough. The permission is `site:view`; a caller
who is not a member of the site gets `404 not_found`, not `403`, so membership cannot be probed.

```sh
BASE=https://stats.example.net

# 1. sign in (keep the cookie jar)
curl -s -c jar.txt -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"correct horse battery staple"}'
# -> {"data":{"status":"ok","user":{…},"csrf_token":"…"}}
#    (or {"data":{"status":"mfa_required"}} when TOTP is enabled)

# 2. call a report
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/overview?period=7d&compare=previous_period"
```

When `data.status` is `mfa_required`, complete the sign-in with `POST /api/v1/auth/mfa` and the same
cookie jar; until then every other API call answers `401 mfa_required`.

### With an API key (server to server)

A server can read the same reports with an API key that has the **`reports:read`** scope — the
WordPress plugin's dashboard does (see [ADR 0009](../architecture/adr/0009-wordpress-plugin-dashboard.md)):

```sh
curl -s -H "Authorization: Bearer ak_1a2b3c4d_…" \
  "$BASE/api/v1/server/sites/pk_XXXXXXXXXXXXXXXXXXXXX/reports/overview?period=7d&compare=previous_period"
```

The routes are `/api/v1/server/sites/{publicKey}/reports/{report}` for `overview`, `timeseries`,
`pages`, `sources`, `tech`, `countries`, `events`, `realtime`, `goals`, `conversions` and `consent`.
They are answered by the same controller as the dashboard's routes: the same parameters, the same
bodies, the same `ETag` and cache headers. The reports that depend on the dashboard's own
configuration (`funnels`, `attribution`, `cohorts`, `content`, event properties, landing pages,
campaigns) stay dashboard-only.

A key belongs to one site; used against another site's public key it answers `404`, and without the
scope `403`. The key is a secret: call these routes from a server, never from a browser (they send no
CORS headers). The other server-to-server read is
`GET /api/v1/server/sites/{publicKey}/content/{contentKey}/stats` (scope `stats:read`), described in
[conversions.md](conversions.md#content-stats).

## Common parameters

| Parameter | Values | Default |
|---|---|---|
| `period` | `today`, `yesterday`, `7d`, `30d`, `90d`, `month`, `last_month`, `12mo`, `year`, `custom` | `30d` |
| `from`, `to` | `YYYY-MM-DD`, required for `period=custom` | — |
| `interval` | `hour`, `day`, `week`, `month` (only meaningful for `overview` and `timeseries`) | by range length: ≤ 1 day → `hour`, ≤ 95 → `day`, ≤ 400 → `week`, else `month` |
| `compare` | `none`, `previous_period`, `previous_year` | `none` |
| `filter[dim][op]` | see below | — |
| `limit` | 1–1000 | 50 |
| `cursor` | opaque, from `meta.next_cursor` | — |
| `sort` | a metric name; `-name` or plain = descending, `+name` = ascending | per report |

Ranges are computed in the **site's time zone** (`meta.timezone`) and are inclusive. The maximum range
is 1900 days. `interval=hour` requires a range of at most 2 days and always reads raw data.

## Filter syntax

```
filter[<dimension>][<operator>]=<value>
```

At most 10 filters per request, each value at most 1024 characters. URL-encode the brackets if your
client does not.

Dimensions: `page`, `entry_page`, `exit_page`, `host`, `channel`, `source`, `referrer`, `utm_source`,
`utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `device`, `browser`, `os`, `country`,
`event`, `content`, `level`.

Operators: `is`, `is_not`, `contains`, `prefix`, `glob` (`*` = any run, `?` = one character).

```sh
# German mobile traffic on the blog
curl -s -b jar.txt -G "$BASE/api/v1/sites/1/reports/pages" \
  --data-urlencode 'period=30d' \
  --data-urlencode 'filter[country][is]=DE' \
  --data-urlencode 'filter[device][is]=mobile' \
  --data-urlencode 'filter[page][prefix]=/blog/'

# visits that fired a given event, excluding one campaign
  --data-urlencode 'filter[event][is]=signup_click' \
  --data-urlencode 'filter[utm_campaign][is_not]=september'

# only consented traffic
  --data-urlencode 'filter[level][is]=c'
```

Semantics worth knowing:

- `is` with an empty value means "NULL or empty"; `is_not` with a value also matches rows where the
  column is NULL.
- `page`, `exit_page`, `event` and `content` on a visit-shaped report become "visits that had such an
  event".
- Filtering usually forces the raw path (`meta.source: "raw"`), which is only possible inside the
  retention window; outside it the answer is `422 filter_unavailable_for_range`.
- `conversions`, `goals`, `consent`, `cohorts`, `realtime`, `funnels` and `attribution` accept no
  filters (`422 filter_unsupported`).
- `overview` and `timeseries` ignore conversions and revenue when filters are present (they come back
  as 0).

## Endpoints

| Endpoint | Extra parameters |
|---|---|
| `GET …/overview` | — |
| `GET …/timeseries` | — |
| `GET …/pages` | `kind=top\|entry\|exit` |
| `GET …/landing-pages` | — |
| `GET …/sources` | `group=channel\|source\|referrer` |
| `GET …/campaigns` | — |
| `GET …/tech` | `group=device\|browser\|os` |
| `GET …/countries` | — |
| `GET …/events` | — |
| `GET …/events/{name}/props` | — |
| `GET …/content` | `prefix=` |
| `GET …/realtime` | — |
| `GET …/goals` | — |
| `GET …/conversions` | — |
| `GET …/funnels/{funnelId}` | `breakdown=none\|channel\|device` |
| `GET …/attribution` | `model=first_touch\|last_non_direct\|declared`, `group=channel\|source\|campaign`, `window=7\|30\|90`, `base=<goal name>`, `target=<goal name>` |
| `GET …/cohorts` | `cohort=week\|month`, `periods=1..13` |
| `GET …/consent` | — |

Sortable metrics per report (`TableReports::SORTABLE`); anything else is `422`:

| Report | Sortable |
|---|---|
| `pages` | `pageviews` (default), `visits`, `visitors`, `entries`, `exits`, `bounce_rate`, `avg_engagement_ms` |
| `landing-pages` | `entries` (default), `bounce_rate` |
| `sources` | `visits` (default), `visitors`, `pageviews`, `bounce_rate`, `avg_duration_ms` |
| `campaigns` | `visits` (default), `visitors`, `pageviews`, `bounce_rate` |
| `tech`, `countries` | `visits` (default), `visitors`, `pageviews` |
| `events`, `event-props` | `occurrences` (default), `visits` |
| `content` | `pageviews` (default), `visits`, `visitors`, `contacts` |
| `conversions` | `count` (default), `value_minor`, `attributed` |

For `pages`, the default sort follows `kind`: `entries` for `kind=entry`, `exits` for `kind=exit`.

## Response envelope

```json
{
  "data": { "...": "report specific" },
  "meta": {
    "site_id": 1,
    "range": { "from": "2026-09-11", "to": "2026-09-17" },
    "compare_range": { "from": "2026-09-04", "to": "2026-09-10" },
    "interval": "day",
    "source": "rollup",
    "availability": { "visitors": true, "bounce": true, "duration": true },
    "generated_at": "2026-09-17T12:00:00+00:00",
    "cache": "miss",
    "timezone": "Europe/Rome",
    "currency": "EUR",
    "next_cursor": null
  }
}
```

`meta.interval` is `null` for every report except `overview` and `timeseries`.
`meta.availability` says which metrics the site can produce at all; unavailable ones are `null` in the
data. See [../privacy/metric-availability.md](../privacy/metric-availability.md).

## Examples

### Overview

```sh
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/overview?period=7d&compare=previous_period"
```

```json
{
  "data": {
    "metrics": {
      "visitors": 3, "visits": 3, "pageviews": 4, "views_per_visit": 1.33,
      "bounce_rate": 0.6667, "avg_duration_ms": 6667, "events": 1,
      "conversions": 0, "revenue_minor": 0, "consented_visits": 0, "consent_rate": 0
    },
    "compare": { "visitors": 0, "visits": 0, "pageviews": 0, "views_per_visit": null,
                 "bounce_rate": null, "avg_duration_ms": null, "events": 0,
                 "conversions": 0, "revenue_minor": 0, "consented_visits": 0, "consent_rate": null },
    "deltas": {}
  },
  "meta": { "source": "rollup", "availability": {"visitors": true, "bounce": true, "duration": true}, "…": "…" }
}
```

`deltas` holds a relative change per metric (`(now − before) / before`, rounded to 4 decimals) and is
`null` for a metric whose previous value was zero or not numeric. Full body:
[examples/report-overview.json](examples/report-overview.json).

### Timeseries

```sh
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/timeseries?period=7d&interval=day"
```

`data.points` is one object per interval:

```json
{"t": "2026-09-16", "visitors": 2, "visits": 2, "pageviews": 3,
 "bounce_rate": 0.5, "avg_duration_ms": 5000, "conversions": 0, "revenue_minor": 0}
```

`t` is `YYYY-MM-DD` for `day`, the Monday of the week for `week`, the first of the month for `month`,
and `YYYY-MM-DD HH:00` for `hour`. Buckets with no data are present with zeros.
`data.compare_points` is `null` unless `compare` is set.

### Pages

```sh
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/pages?period=7d&sort=-pageviews&limit=50"
```

```json
{
  "data": {
    "rows": [
      { "host": "www.site.test", "path": "/pricing", "pageviews": 2, "visits": 2, "visitors": 2,
        "entries": 1, "exits": 1, "bounce_rate": 0, "avg_engagement_ms": 3500 }
    ],
    "total_rows": 3
  },
  "meta": { "next_cursor": null, "…": "…" }
}
```

Full body: [examples/report-pages.json](examples/report-pages.json).

### Realtime

```sh
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/realtime"
```

```json
{
  "data": {
    "active_visitors": 1,
    "pageviews_per_minute": [{ "t": "2026-09-17 11:31", "pageviews": 0 }, "… 30 entries …"],
    "top_pages": [{ "host": "www.site.test", "path": "/live", "pageviews": 1 }],
    "top_sources": [{ "channel": "direct", "source": null, "visits": 1 }]
  }
}
```

"Active" means an event received in the last 5 minutes; the series and the top lists cover 30 minutes.
Cached for 10 seconds.

### Pagination

```sh
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/pages?period=30d&limit=2"
# meta.next_cursor: "bzI"
curl -s -b jar.txt "$BASE/api/v1/sites/1/reports/pages?period=30d&limit=2&cursor=bzI"
```

Cursors are opaque and encode an offset; the maximum offset is 100 000.

### CSV

```sh
curl -s -b jar.txt -H 'Accept: text/csv' \
  "$BASE/api/v1/sites/1/reports/pages?period=7d" -o pages.csv
```

`Content-Disposition: attachment; filename="pages-2026-09-11-2026-09-17.csv"`, UTF-8 with a BOM, CRLF
line endings. Reports without `rows` or `points` answer `406 csv_unavailable`.

### Conditional requests

```sh
curl -s -b jar.txt -D - -o /dev/null "$BASE/api/v1/sites/1/reports/overview?period=7d"
# ETag: "3f0a…"   Cache-Control: private, max-age=30
curl -s -b jar.txt -H 'If-None-Match: "3f0a…"' "$BASE/api/v1/sites/1/reports/overview?period=7d" -o /dev/null -w '%{http_code}\n'
# 304
```

## Errors

All errors are RFC 9457 problem documents; see [errors.md](errors.md). The ones specific to reporting
are `validation_failed`, `filter_unsupported`, `filter_unavailable_for_range`, `range_too_large`,
`funnel_incomplete` and `csv_unavailable`.
