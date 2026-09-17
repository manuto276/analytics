# 0003. Monthly MySQL partitioning for the hot tables

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

Raw events and visits are kept for 13 months and then deleted. On a table with tens of millions of
rows, a `DELETE … WHERE local_day < ?` is slow, generates a large amount of undo and redo, leaves the
table fragmented and competes with ingestion on a small server. Reports, on the other hand, almost
always filter on a date range, so the engine should be able to skip whole months.

MySQL 8.4 supports `RANGE COLUMNS` partitioning on a `DATE` column, with `DROP PARTITION` as an
instant metadata operation. The constraints are: every unique key must contain the partitioning
column, and foreign keys are not allowed at all. Some managed hosts disable partitioning.

## Decision

`events_raw` and `visits` are partitioned by `RANGE COLUMNS(local_day)`, one partition per month.

- `Analytics\Shared\Doctrine\Partitioning` owns the naming (`pYYYYMM`), the catch-all `pmax`, the
  pre-history partition `p_old` and the `CREATE TABLE` clause used by migration
  `Version20260917000002`.
- Primary keys are `(id, local_day)` and the event uniqueness constraint is
  `(site_id, event_uid, local_day)` — the partitioning column is part of both.
- Neither table has a foreign key. Integrity is the application's job.
- `bin/analytics partitions:maintain [--ahead=3]` reorganises `pmax` to add the current month plus
  the next three; `--past-from=YYYY-MM-DD` splits `p_old` into months for imported history. It runs
  daily from cron, not from a migration.
- `bin/analytics retention:purge` drops every partition whose whole range is before the cutoff and
  then removes the stragglers with chunked `DELETE … LIMIT 5000`.
- `DB_PARTITIONING=false` creates the same tables without partitions; retention then uses only the
  chunked delete.

## Consequences

- Deleting 13-month-old data is an `ALTER TABLE … DROP PARTITION`: near-instant, no undo, no
  fragmentation.
- Range-filtered report queries prune partitions;
  `Analytics\Tests\Integration\Reporting\QueryPlanTest::testRawReportQueriesUseIndexesAndPartitionPruning`
  asserts it through `EXPLAIN`.
- Partitions are operational state, not schema: a database restored from a dump taken months ago
  needs `partitions:maintain` before it can accept today's writes — otherwise rows land in `pmax`,
  which still works but cannot be dropped cheaply.
- If `partitions:maintain` stops running, ingestion keeps working (everything falls into `pmax`) but
  retention degrades to chunked deletes for those months.
- Changing a site's time zone moves rows between `local_day` values and therefore between partitions;
  `rollup:rebuild --recompute-days` does that explicitly.
- Covered by `Analytics\Tests\Migrations\MigrationsTest::testPartitionsCoverTheCurrentMonthAndCanBeExtended`,
  `::testDatabaseWithoutPartitioningWorks` and
  `Analytics\Tests\Integration\Retention\RetentionTest::testPurgeRemovesOldRawDataButKeepsRollups`.
