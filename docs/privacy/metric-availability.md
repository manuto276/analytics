# Metric availability

Which metrics exist depends on the site's `visitor_hash_mode` and on whether the visitor is at the
base or the cookie level. The API states this per request in `meta.availability`; unavailable metrics
are returned as `null`, never as `0`.

```jsonc
"availability": { "visitors": true, "bounce": true, "duration": true }
```

Computed in `ReportService::run()`:

- `visitors` — `visitor_hash_mode = daily_hash` **or** `cookie_level_enabled`
- `bounce` — `visitor_hash_mode = daily_hash`
- `duration` — `visitor_hash_mode = daily_hash`

Bounce rate and duration are visit-level metrics, and visits only exist when there is a visitor key to
group events by. On a `pageviews_only` site there is none.

## Per site mode

| Metric | `daily_hash` | `pageviews_only` |
|---|---|---|
| Pageviews | yes | yes |
| Custom events and properties | yes | yes |
| Engagement time, scroll depth (per page) | yes | yes |
| Visits | yes (sessions, 30-minute inactivity) | estimated: each entry pageview counts as one visit |
| Visitors (per day) | yes — distinct daily hashes | only consented visitors, if the cookie level is on |
| Bounce rate | yes | **no** (`null`) |
| Average visit duration | yes | **no** (`null`) |
| Entry / exit pages | yes | entry pages only, from `is_entry` |
| Channels, sources, campaigns | yes | yes (per entry pageview) |
| Countries, devices, browsers, operating systems | yes | yes |
| Content groups | yes | yes |
| Realtime | yes | yes |
| Consent statistics | yes | yes |
| Goals (pageview / event type) | yes | yes |
| Goals (conversion type), conversions, revenue | yes | yes |
| Funnels, `scope=visit` | yes | **no** — there are no visits to group by |
| Funnels, `scope=visitor` | cookie level only | cookie level only |
| Attribution, CAC, ROAS | cookie level only (needs touches) | cookie level only |
| Cohorts / retention | cookie level only | cookie level only |

## Per level, within one site

| Metric | Base level | Cookie level |
|---|---|---|
| Everything in the table above that does not need identity | yes | yes |
| Distinct visitors **within a day** | yes (`daily_hash`) | yes |
| Returning visitors, cohorts, retention | no | yes |
| Attribution touches, campaign attribution of a conversion | no | yes |
| Visits spanning UTC midnight | split in two | not split (the session id survives) |
| `POST /t/forget` erasure | not applicable — nothing is linked | applicable |

A single site can produce both at once: visitors who accepted are at the cookie level, the rest stay
at the base level. `rollup_overview_daily.consented_visits` and the `consent_rate` metric show the
proportion.

## What "visitors" means

In every daily rollup and therefore in every range query, `visitors` is **visitor-days**: the sum over
the days of the range of the distinct visitors seen on each day. Someone visiting on three days of a
week contributes 3.

This is a deliberate consequence of the daily salt — across days there is no identity to count — and
of keeping the rollups additive ([../architecture/adr/0002-additive-rollups-visitor-days.md](../architecture/adr/0002-additive-rollups-visitor-days.md)).

It is comparable over time and between segments, which is what it is used for. It must not be
presented as "unique people". The only true distinct count is
`rollup_consented_visitors_monthly`, which counts distinct `visitor_id` per month and therefore covers
consented visitors only.

## Practical consequences

- A site in `pageviews_only` mode gets pageviews, events, sources and technology breakdowns, but the
  overview shows `null` for bounce rate and average duration and the dashboard hides those tiles.
- Switching a site from `daily_hash` to `pageviews_only` does not delete history; the older rows keep
  their hashes and their visits. New data simply stops producing them.
- Switching the cookie level on does not retroactively create visitors or touches.
- Filters on `level` (`filter[level][is]=c`) let a report be restricted to consented traffic, which is
  the honest way to read cohort-like numbers.

See [two-levels.md](two-levels.md) and [../architecture/reporting.md](../architecture/reporting.md).
