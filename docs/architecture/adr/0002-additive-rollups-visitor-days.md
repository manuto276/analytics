# 0002. Additive rollups and visitor-days

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

The dashboard must answer "last 30 days", "last 12 months" and arbitrary custom ranges quickly on a
small shared server, over tables that grow to millions of rows per month. Scanning `events_raw` for
every dashboard load is not affordable.

Pre-aggregating per day solves that, but only if range queries are a plain `SUM` over the daily rows.
The moment one metric is not additive, the whole table stops being usable for ranges. Distinct
visitors is exactly such a metric: the number of distinct people over 30 days is not the sum of 30
daily counts.

The alternatives were: (a) store sketches (HyperLogLog) per day and merge them; (b) fall back to raw
data whenever a range is requested; (c) redefine the metric.

(a) adds a dependency, an approximation error that is hard to explain to a user, and — for base-level
data — would need an identifier that survives the day, which the daily salt deliberately destroys.
(b) defeats the purpose. And for base-level data (c) is not a compromise at all: the daily salt means
there *is* no identity across days, so distinct-people-over-a-range is not a number this system can
compute even in principle.

## Decision

Every daily rollup contains only additive metrics, and `visitors` in a daily rollup means
**visitor-days**: the count of distinct visitors *on that day*, summed across the days of the range.

- `RawSelects::VISITORS_V` / `VISITORS_E` compute
  `COUNT(DISTINCT visitor_hash) + COUNT(DISTINCT CASE WHEN visitor_hash IS NULL THEN visitor_id END)`
  per day.
- Range queries are `SUM(visitors)` over `rollup_*_daily`.
- The exception is `rollup_consented_visitors_monthly`, which stores **true** monthly uniques
  (`COUNT(DISTINCT visitor_id)`) for consented visitors only, split by `total`, `channel` and
  first-seen `cohort`. That is possible because a consented visitor has a stable `an_vid` cookie.
- The same SQL fragments (`RawSelects`) are used for the rollup build and for the raw fallback, so the
  two paths agree by construction rather than by review.

## Consequences

- A 30-day "visitors" figure is larger than the number of people. It is comparable over time and
  across segments, which is what it is used for, but it must never be presented as "unique people".
  [../../privacy/metric-availability.md](../../privacy/metric-availability.md) says so explicitly and
  the dashboard shows the availability flags from `meta.availability`.
- Rollups can be rebuilt one `(site, day)` at a time, which makes `rollup:run` incremental, cheap and
  restartable, and makes `retention:purge` safe: dropping a month of raw data never invalidates a
  rollup.
- Any new rollup metric must be additive. A ratio (bounce rate, average duration) is stored as its
  numerator and denominator and divided at read time.
- Cohort and retention analysis is available only for the cookie level.
- Pinned by `Analytics\Tests\Integration\Reporting\RollupConsistencyTest::testRollupAndRawAgree` and
  `::testRollupsAreIdempotentAndClearDirtyDays`.
