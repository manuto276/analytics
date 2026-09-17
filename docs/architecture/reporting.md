# Reporting

Reports are answered either from the daily rollup tables or from raw data, with the same SQL
fragments in both cases. The path is
`ReportsController` -> `ReportQueryFactory` -> `ReportService` -> `QueryPlanner` -> report class ->
`ReportCache` -> envelope.

## Rollups

`rollup:run` (cron, every 5 minutes) takes the oldest rows of `rollup_dirty`, rebuilds every rollup of
that `(site_id, day)` and deletes the dirty mark. A day whose build throws is **left dirty and
skipped**: the run carries on with the other rows and ends by throwing a `RollupRunFailed` summary, so
the job is recorded as failed without one unbuildable day blocking every other site for ever (it is
ordered first by `first_marked_at`, so it would be retried before anything else on every run).

`RollupRunFailed` implements `Analytics\Shared\Jobs\JobStats`, so the counts the run did produce
(`days`, `sites`, `failed`) survive the failure: `JobRunner` records them on the failed `job_runs`
row instead of an empty object, and `jobs:status` / `GET /api/v1/admin/jobs` show them as
`last_stats`. Any module's exception can opt in the same way without `Shared` knowing about it.

`rollup:rebuild --site --from --to` marks a range dirty and rebuilds it; with `--recompute-days` it
first recalculates `local_day` on the raw rows in the site's *current* time zone — what you run after
changing a site's time zone. The recompute keeps the invariant every reporting join relies on:

- `visits.local_day` comes from `started_at`;
- an event that belongs to a visit takes **the visit's** day, never its own — that is the ingest rule
  (`IngestBatchHandler::resolveVisit()` only reuses a visit whose day is the event's day), so a visit
  that straddles the new midnight keeps all of its events;
- a standalone event (`visit_id IS NULL`) keeps its own day, recomputed from `occurred_at`;
- `visit_lookup.visit_day` and `attribution_touches.visit_day` follow the visit;
- the rebuilt range is the requested range **plus every day a row moved out of or into**, which may be
  one day outside it in either direction.

`RollupBuilder::build()` is a `DELETE` followed by `INSERT … SELECT` per table, inside one
transaction, which makes it idempotent: running it twice on the same day gives the same rows. After a
batch of days, `RollupRunner` increments `sites.rollup_version`, which invalidates the report cache.

Tables rebuilt per day (`RollupBuilder::DAILY_TABLES`): `rollup_overview_daily`, `rollup_pages_daily`,
`rollup_landing_daily`, `rollup_sources_daily`, `rollup_campaigns_daily`, `rollup_tech_daily`,
`rollup_geo_daily`, `rollup_events_daily`, `rollup_content_daily`, `rollup_conversions_daily`. In
addition, every build refreshes `rollup_consented_visitors_monthly` for the month of that day
(`total`, `channel` and first-seen `cohort` dimensions), which holds **true** monthly uniques for
consented visitors.

Column lists are in [data-model.md](data-model.md).

### Equivalence by construction

`Analytics\Reporting\Application\Rollup\RawSelects` holds one static method per rollup, returning a
per-day aggregate `SELECT` over `events_raw` and `visits`. Those exact strings are used twice:

- the rollup builder wraps them in `INSERT … SELECT` for a single day;
- `TableReports`/`DailyMetrics` wrap the same strings in an outer `GROUP BY` over a date range, with
  the filter conditions appended, when the planner chooses raw.

So "rollup" and "raw" are not two implementations of a metric — they are the same expression evaluated
over one day and stored, or evaluated over N days on the fly. Any drift would have to be introduced
deliberately. The property is asserted by
`Analytics\Tests\Integration\Reporting\RollupConsistencyTest::testRollupAndRawAgree`, which runs every
report with several parameter sets against a seeded dataset both ways and compares the output, and by
`::testRollupsAreIdempotentAndClearDirtyDays`.

Because every rollup metric is a plain `SUM` over days, a range never needs raw data. The price is
that `visitors` is **visitor-days**: a person visiting on three days counts three times in a 7-day
figure. See [adr/0002-additive-rollups-visitor-days.md](adr/0002-additive-rollups-visitor-days.md) and
[../privacy/metric-availability.md](../privacy/metric-availability.md).

### Visitors expression

```sql
COUNT(DISTINCT v.visitor_hash)
  + COUNT(DISTINCT CASE WHEN v.visitor_hash IS NULL THEN v.visitor_id END)
```

Base-level rows are counted by their daily hash; consented rows without a hash (a `pageviews_only`
site) are counted by their visitor id. The two terms never overlap, so the sum is a count of distinct
visitors for that day.

### Orphan pageviews
<a id="orphan-pageviews"></a>

On a `pageviews_only` site there are no visits, so a pageview flagged `is_entry = 1` (a pageview not
arriving from the site itself) is counted as one visit by the raw selects
(`SUM(e.visit_id IS NULL AND e.is_entry = 1)`). That is how such sites still get a visits figure —
without sessions, bounce rate or duration.

## Query planner

`Analytics\Reporting\Application\QueryPlanner::plan()`:

1. Every filter dimension must be one of `Filter::DIMENSIONS`, otherwise `422 validation_failed`.
2. Reports in `QueryPlanner::UNFILTERABLE` (`conversions`, `goals`, `consent`, `cohorts`, `realtime`,
   `funnels`, `attribution`) reject any filter with `422 filter_unsupported`.
3. `tech` with a filter on a dimension other than the requested `group` goes straight to raw.
4. Otherwise the report's rollup can answer the query when every filter dimension is listed in
   `QueryPlanner::ROLLUP_DIMENSIONS` for that report **and** the interval is not `hour`.
5. Anything else is a raw plan, which additionally requires the range to start inside the retention
   window (`RETENTION_MONTHS`, default 13); if not -> `422 filter_unavailable_for_range`.

`realtime` always uses raw data. The chosen source is reported in `meta.source` (`rollup` or `raw`).

| Report | Filter dimensions its rollup covers |
|---|---|
| `overview`, `timeseries` | none (any filter forces raw) |
| `pages` | `page`, `host` |
| `landing-pages` | `entry_page`, `page`, `host`, `channel` |
| `sources` | `channel`, `source`, `referrer` |
| `campaigns` | `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` |
| `tech` | `device`, `browser`, `os` |
| `countries` | `country` |
| `events`, `event-props` | `event` |
| `content` | `content`, `channel` |

## Filters

Syntax: `filter[dimension][operator]=value`, at most 10 per request, values at most 1024 characters.

Dimensions (`Analytics\Reporting\Domain\Filter`):

- visit dimensions — `entry_page`, `exit_page`, `host`, `channel`, `source`, `referrer`,
  `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `device`, `browser`, `os`,
  `country`, `level`;
- event dimensions — `page`, `event`, `content`.

Operators (`FilterOperator`): `is`, `is_not`, `contains`, `prefix`, `glob`.

`SqlFilters` translates them. What the semantics are, precisely:

| Case | Meaning |
|---|---|
| `is` with an empty value | the column is NULL or empty |
| `is_not` with an empty value | the column is set and not empty |
| `is_not` with a value | the column is NULL **or** different — a missing value satisfies a negative filter |
| `contains` / `prefix` | `LIKE` with `\`, `%` and `_` escaped |
| `glob` | `*` -> `%`, `?` -> `_`, everything else escaped |
| `page` on a visit query | `EXISTS` a pageview of that visit whose path matches |
| `exit_page` on a visit query | `EXISTS` a pageview of that visit whose `page_hash` equals the visit's `exit_page_hash` and whose path matches |
| `entry_page` / `exit_page` on an event query | the entry/exit page of the visit the row belongs to; a row that has no visit but *is* an entry (see [orphan pageviews](#orphan-pageviews)) is the whole visit, so it answers with its own path |
| `event` on a visit query | `EXISTS` a custom event of that visit with that name — "visits that fired this event" |
| `content` | the visit's `entry_content_key` matches **or** any event of the visit has a matching `content_key` |
| campaign/source dimensions on an event query with a visit join | `COALESCE(visit column, event column)` — visit-level attribution wins |
| `event` on the events report | applied to the row itself, not as an `EXISTS` |

Two rules keep a translated filter list honest:

- each translation namespaces its own placeholders (`forVisits` binds `fv0…`, `forEvents` `fe0…`,
  `forRollup` `fr0…`). `TableReports::rawInner()` merges the visits half and the events half of the
  same filter list into one query, and a dimension can need a different number of placeholders on the
  two sides (`content` emits two conditions on the visits side, one on the events side), so shared
  numbering would make one half read the next filter's value — and the answer would depend on the
  order the filters were written in;
- a condition is never spliced into the `ON` clause of a `LEFT JOIN`. There it filters nothing, it
  only makes the joined row NULL. Where a report joins `visits` purely to resolve attribution
  (`pages`, `content`), the `ON` clause carries only the day range, so that MySQL can prune the
  partitions of `visits`, and every filter goes into the `WHERE` clause of the events side, resolved
  through `COALESCE(v.…, e.…)` — the same expression the rollup groups by.

`SqlFilters::forEvents()` takes an `EventRows` saying what the rows of the events side are, and there
are only two answers:

- `JoinedToVisits` — the query carries `RawSelects::visitsJoin()`, so the visit answers for the row
  (`COALESCE(v.entry_path, …)`, an `EXISTS` on `v.exit_page_hash`, `COALESCE(v.…, e.…)` for
  attribution). Every report that has an arm of ordinary events uses this;
- `OrphanEntries` — the arm holds only `visit_id IS NULL AND is_entry = 1` rows, which are whole
  one-pageview visits, so they answer `entry_page` and `exit_page` with `e.path` and attribution with
  their own columns. `landing-pages` and `sources` have exactly such an arm, and `entry_page` is a
  dimension the landing rollup covers, so the two sources have to agree on it.

Because there is no third case, no filter the API accepts can reach an events query that cannot
translate it: `entry_page`/`exit_page` used to raise `InvalidArgumentException` ("needs visit data")
— a **500** on a plain query parameter — on every report whose raw query had no visits join
(`landing-pages`, `sources`, `campaigns`, `tech`, `countries`, `events`, `event-props`), which a
second filter outside that report's rollup dimensions was enough to reach.
`Analytics\Tests\Integration\Reporting\VisitPageFilterTest` checks every report against a direct
SQL oracle for both dimensions.

Filters are not applied to the conversion figures inside `overview`/`timeseries`: when filters are
present, `DailyMetrics::raw()` skips the conversions query entirely, so `conversions` and
`revenue_minor` are 0 in a filtered overview.

## Reports

| Report | Path suffix | Options | Output |
|---|---|---|---|
| overview | `/overview` | — | `metrics`, `compare`, `deltas` |
| timeseries | `/timeseries` | — | `points`, `compare_points` |
| pages | `/pages` | `kind=top\|entry\|exit` | host, path, pageviews, visits, visitors, entries, exits, bounce_rate, avg_engagement_ms |
| landing pages | `/landing-pages` | — | host, path, entries, bounces, bounce_rate |
| sources | `/sources` | `group=channel\|source\|referrer` | channel, source, referrer_host, visits, visitors, pageviews, bounce_rate, avg_duration_ms |
| campaigns | `/campaigns` | — | utm_*, visits, visitors, pageviews, bounce_rate |
| tech | `/tech` | `group=device\|browser\|os` | value, visits, visitors, pageviews |
| countries | `/countries` | — | country (null = unknown), visits, visitors, pageviews |
| events | `/events` | — | name, occurrences, visits |
| event props | `/events/{name}/props` | — | prop_key, prop_value, occurrences, visits |
| content | `/content` | `prefix=` | content_key, pageviews, visits, visitors, contacts, channels |
| realtime | `/realtime` | — | active_visitors (5 min), pageviews_per_minute (30 min), top_pages, top_sources |
| goals | `/goals` | — | goal_id, name, type, conversions, conversion_rate, value_minor |
| conversions | `/conversions` | — | name, count, value_minor, attributed |
| funnel | `/funnels/{id}` | `breakdown=none\|channel\|device` | step counts, conversion and drop-off, optional breakdown |
| attribution | `/attribution` | `model=first_touch\|last_non_direct\|declared`, `group=channel\|source\|campaign`, `window=7\|30\|90`, `base=`, `target=` | per group: visits, base/target conversions, revenue, cost, CAC, ROAS; totals with the unattributed share |
| cohorts | `/cohorts` | `cohort=week\|month`, `periods=1..13` | retention grid of consented visitors |
| consent | `/consent` | — | per day and version: shown, accepted, rejected, dismissed, reopened; totals with acceptance rate |

`event-props` reads each value out of the stored JSON with a path built from the key. The key is
quoted with `JSON_QUOTE`, so it is always data and never part of the path syntax: a key holding `"`,
`\` or a control character used to make MySQL raise *Invalid JSON path expression* and fail the whole
rollup build for that day.

The stored key must also *fit* `rollup_events_daily.prop_key` (`VARCHAR(32)`). Ingestion validates
the key against an allow-list and then scrubs it, and the scrubber substitutes (`[number]`,
`[email]`, `[phone]`), so the key that is stored is not the key that was validated and is not bounded
by its length. `PiiScrubber::scrubPropKey()` therefore re-checks the *result*: a key that no longer
fits is refused and ingestion drops that one property, keeping the event. The `[` and `]` the
placeholders introduce are deliberately kept — the allow-list does not permit them, but `JSON_QUOTE`
takes any key as data, and a property reported under `user[number]` is more useful than none.

Campaign cost in `attribution` is prorated over **local days**: the overlap between the campaign's
`day_from`/`day_to` and the report range is computed on the dates themselves, never on `DateTime`s of
different zones, so a campaign that covers the whole range contributes its whole budget whatever the
site's time zone (and whatever DST does inside the range).

Sorting is restricted per report by `TableReports::SORTABLE`; anything else is a `422`. `sort=-visits`
(or plain `visits`) is descending, `sort=+visits` ascending. Derived sorts (`bounce_rate`,
`avg_duration_ms`, `avg_engagement_ms`) are rewritten to the underlying ratio.

The funnel report refuses a funnel with fewer than two usable steps (`409 funnel_incomplete`) and a
range producing more than 200 000 subjects (`422 range_too_large`).

## Envelope

```jsonc
{
  "data": { },
  "meta": {
    "site_id": 1,
    "range": {"from": "2026-08-19", "to": "2026-09-17"},
    "compare_range": null,
    "interval": "day",              // only for overview and timeseries
    "source": "rollup",             // or "raw"
    "availability": {"visitors": true, "bounce": true, "duration": true},
    "generated_at": "2026-09-17T12:00:00+00:00",
    "cache": "hit",                 // or "miss"
    "timezone": "Europe/Rome",
    "currency": "EUR",
    "next_cursor": null
  }
}
```

`availability` is derived from the site: `visitors` when the hash mode is `daily_hash` **or** the
cookie level is enabled; `bounce` and `duration` only when the hash mode is `daily_hash`. Metrics that
are unavailable come back as `null` rather than 0. See
[../privacy/metric-availability.md](../privacy/metric-availability.md).

`next_cursor` is present on table reports when `offset + limit < total_rows`. Cursors are opaque
(`base64url("o" + offset)`), capped at offset 100 000.

## Caching and ETag

`ReportCache` keys an entry on `report . site_id . rollup_version . fingerprint`, where the
fingerprint is an xxh128 hash of the site, range, compare range, interval, sorted filters, limit,
offset, sort and options. Entries are tagged `site-<id>`, so `rollup:run` bumping `rollup_version`
makes every cached report for that site unreachable at once.

| Case | TTL |
|---|---|
| `realtime` | 10 s |
| range includes today (or is in the future) | 60 s |
| entirely in the past | 24 h |

The HTTP layer adds `Cache-Control: private, max-age=30` and a strong `ETag` computed from the
serialised `data` (not `meta`, which contains `generated_at`), and answers `304` on a matching
`If-None-Match`.

## CSV

Send `Accept: text/csv`. The exporter flattens `data.rows` (or `data.points`); a report with neither
gives `406 csv_unavailable`. The file is UTF-8 with a BOM, CRLF line endings, RFC 4180 quoting, and is
served as `Content-Disposition: attachment; filename="<report>-<from>-<to>.csv"` with the same
`Cache-Control: private, max-age=30`.

## Performance

`Analytics\Tests\Integration\Reporting\QueryPlanTest` runs `EXPLAIN` on the raw report queries and
asserts that they use an index and prune partitions, that selective queries use an index, and that
rollup queries hit the primary key. Adding a report query without an index will fail that test.

Every join of `visits` onto events goes through `RawSelects::visitsJoin()` and every caller passes it
the day range the events already carry. Without it the join has no restriction on `visits.local_day`
and MySQL reads **all thirteen months** of partitions to answer a query about three days, so each
such join is in the data provider of that test twice: once bare and once joined.

## External read API

`GET /api/v1/server/sites/{publicKey}/content/{contentKey}/stats?days=30` (scope `stats:read`) reads
`rollup_content_daily` and returns pageviews, visitors, contacts and a per-channel visit split. When
the visitor count is below the site's `min_group_size` (default 5) every figure is `null` and
`suppressed` is `true`. `days` is 1-395.
