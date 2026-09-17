# Retention

Raw, visitor-level data is deleted after `RETENTION_MONTHS` (default 13). Aggregate rollups are kept
indefinitely, so historic charts survive the deletion of the rows they were built from.

The command is `bin/analytics retention:purge`, run daily from cron
([cron.md](cron.md)).

## Windows

| Data | Window | Column | How it is removed |
|---|---|---|---|
| `events_raw` | `RETENTION_MONTHS` | `local_day` | drop whole monthly partitions, then chunked delete for the rest |
| `visits` | `RETENTION_MONTHS` | `local_day` | same |
| `visit_lookup` | `RETENTION_MONTHS` | `visit_day` | chunked delete |
| `attribution_touches` | `RETENTION_MONTHS` | `touched_at` | chunked delete |
| `conversions` | `RETENTION_MONTHS` | `local_day` | chunked delete |
| `consent_receipts` | `RETENTION_MONTHS` | `decided_at` | chunked delete |
| `visitors` | `RETENTION_MONTHS` | `last_seen_at` | chunked delete |
| `consent_stats_daily` | `RETENTION_MONTHS` | `day` | chunked delete |
| `audit_log` | 24 months (`RetentionPurger::AUDIT_MONTHS`) | `occurred_at` | chunked delete |
| `job_runs` | 90 days (`JOB_RUNS_DAYS`) | `started_at` | chunked delete |
| `auth_sessions` | expired or revoked | `absolute_expires_at`, `idle_expires_at`, `revoked_at` | delete |
| `invitations` | 30 days after acceptance or expiry (`ACCEPTED_INVITATION_DAYS`) | `accepted_at`, `expires_at` | delete |
| `password_resets` | on expiry | `expires_at` | delete |
| `daily_salts` | one day | `day` | deleted on every salt read and by `salt:rotate`, not by this command |
| every `rollup_*` table | **kept** | — | — |

The cutoff is `now − RETENTION_MONTHS`, truncated to midnight. Chunked deletes run
`DELETE … WHERE <column> < ? LIMIT 5000` in a loop, so they never hold a long transaction.

## The refusal

`retention:purge` **refuses to run** while a day before the cutoff is still listed in `rollup_dirty`:

```
3 day(s) before the cutoff still need rollup:run; refusing to purge (use --force to override).
```

Otherwise raw rows would be deleted before they had been aggregated, and the numbers for those days
would be lost for good. Fix the backlog first:

```sh
php bin/analytics rollup:run --limit=2000     # repeat until jobs:status shows 0 dirty days
php bin/analytics retention:purge
```

`--force` skips the check. Only use it when you have accepted losing those days' aggregates.

## Usage

```sh
php bin/analytics retention:purge --dry-run     # counts events_raw, visits and conversions only
php bin/analytics retention:purge
php bin/analytics retention:purge --force
```

The job is recorded in `job_runs` with per-table counts and the list of dropped partitions.

## Changing the window

Set `RETENTION_MONTHS` in the environment and restart PHP; the next run uses it. Lowering it deletes
more on the next run — it is not reversible. Raising it does not bring data back.

`RETENTION_MONTHS` also bounds what the query planner will attempt: a report that needs raw data for a
range starting before the cutoff answers `422 filter_unavailable_for_range`
([../api/errors.md](../api/errors.md)). Cohorts are limited the same way.

There is **no per-site retention setting**; the value is global.

## Partitions

Dropping a partition is a metadata operation: instant, no undo log, and the space goes back to the
filesystem. That only works when `partitions:maintain` has been keeping the layout in shape, so the
two jobs are scheduled together (03:20 daily).

Check what exists:

```sql
SELECT table_name, partition_name, table_rows,
       ROUND((data_length + index_length)/1024/1024) AS mb
  FROM information_schema.partitions
 WHERE table_schema = DATABASE() AND table_name IN ('events_raw','visits')
 ORDER BY table_name, partition_ordinal_position;
```

A single row with `partition_name` NULL means the table is not partitioned — either
`DB_PARTITIONING=false` was set at install time, or the host does not support it. Retention still
works through chunked deletes, but it is slower and does not return space until the table is rebuilt.

Data older than the first partition boundary lives in `p_old`. To make it droppable, split it once:

```sh
php bin/analytics partitions:maintain --past-from=2025-01-01
```

## Erasure on request

Retention is time-based and applies to everyone. An individual erasure is `POST /t/forget` (or
`analytics.consent.forget()` in the browser), which removes that visitor's cookie-level rows
immediately and detaches their conversions. See
[../architecture/ingestion.md](../architecture/ingestion.md#11-forget).

Base-level rows are not linked to a person and are not affected — there is nothing to look them up by.

## What retention does not cover

- **Database backups.** A dump keeps 13 months of data for as long as you keep the dump. Set a backup
  retention too — [backups.md](backups.md).
- **Web server access logs.** Anonymise or disable them for `/t/`, and rotate them
  ([../deploy/nginx.md](../deploy/nginx.md#anonymised-logs)).
- **Application logs.** Addresses are masked by `IpScrubbingProcessor`, but rotate `LOG_DIR` anyway.
- **Read replicas or exports** you created yourself.

## Verification

```sh
php bin/analytics retention:purge --dry-run
php bin/analytics jobs:status
```

```sql
SELECT MIN(local_day) FROM events_raw;   -- must be >= cutoff
SELECT MIN(day) FROM rollup_pages_daily; -- may be much older: rollups are kept
```

`Analytics\Tests\Integration\Retention\RetentionTest` covers all of it with a frozen clock:
`testPurgeRemovesOldRawDataButKeepsRollups`, `testPurgeRefusesWhenDaysStillNeedARollup`,
`testOperationalTablesHaveTheirOwnWindows`.

## Related

[cron.md](cron.md) · [backups.md](backups.md) · [../privacy/data-inventory.md](../privacy/data-inventory.md) ·
[../architecture/data-model.md](../architecture/data-model.md)
