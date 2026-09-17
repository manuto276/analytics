# Scheduled jobs

Five commands keep the system healthy. Without them the dashboard stops updating, the privacy
invariant on the daily salt is only enforced opportunistically, partitions run out and old data is
never deleted.

Every job takes a `symfony/lock` (Redis when `REDIS_DSN` is set, otherwise a file lock under
`STORAGE_DIR/locks`) and records a row in `job_runs`, so an overlapping run is a no-op and
`jobs:status` shows the lag.

## The schedule

| When | Command | Purpose | Lock TTL |
|---|---|---|---|
| every 5 min | `rollup:run -q` | rebuild the rollups of the days marked dirty by ingestion | 3600 s |
| every 15 min | `salt:rotate -q` | ensure today's visitor-hash salt exists and destroy older ones | 300 s |
| every minute *(queue mode only)* | `queue:work --max-time=55 -q` | write the events queued in Redis | — |
| daily 03:20 | `partitions:maintain -q` then `retention:purge -q` | create the coming months' partitions, then delete data past the retention window | 600 s / 3600 s |
| monthly, 5th 04:40 | `geo:update -q` | download the DB-IP Lite country database and swap it in | 900 s |

## Tarball deployment

`deploy/examples/crontab.example`, in the **site user's** crontab. Always go through `current`, so the
jobs follow deploys and rollbacks, and use `nice` so they yield to web traffic.

```cron
SHELL=/bin/sh
MAILTO=""

*/5  * * * * cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics rollup:run -q >> var/log/cron.log 2>&1
*/15 * * * * cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics salt:rotate -q >> var/log/cron.log 2>&1
# * * * * * cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics queue:work --max-time=55 -q >> var/log/cron.log 2>&1
20 3 * * *   cd /home/site/htdocs/stats.example.net/current && (nice -n 10 /usr/bin/php8.4 bin/analytics partitions:maintain -q && nice -n 10 /usr/bin/php8.4 bin/analytics retention:purge -q) >> var/log/cron.log 2>&1
40 4 5 * *   cd /home/site/htdocs/stats.example.net/current && nice -n 10 /usr/bin/php8.4 bin/analytics geo:update -q >> var/log/cron.log 2>&1
```

Use the same PHP binary as `php_binary` in `deploy.ini`. `var/log` inside `current` is a symlink into
`shared/`, so the log survives releases.

## Docker

The `scheduler` profile runs `deploy/docker/cron/crontab` with supercronic, so job output goes to
`docker logs`:

```sh
docker compose -f deploy/docker/compose.prod.yml --profile scheduler up -d
```

**Without that profile nothing is scheduled.** The alternative is the same lines in the host's cron
with `docker compose … exec -T app php bin/analytics …`.

In queue mode use the `queue` profile instead of the cron line: it runs a long-lived
`queue:work --max-time=3600` worker. The packaged crontab keeps that line commented out for exactly
that reason.

## The commands in detail

### `rollup:run [--site=<id|public key>] [--limit=500]`

Takes the oldest rows of `rollup_dirty` (up to `--limit`, default 500), rebuilds every rollup of that
`(site, day)` inside a transaction, deletes the dirty mark and finally increments
`sites.rollup_version`, which invalidates that site's report cache.

Safe to run more often than every 5 minutes; a run with nothing dirty is cheap. Two runs never
overlap.

### `salt:rotate`

Ensures today's salt exists and deletes every earlier one. Ingestion already does this on the first
event of the day; the job matters on quiet sites, where yesterday's salt would otherwise linger in the
table until the next visit. `health:check` reports a warning while a stale salt exists.

### `partitions:maintain [--ahead=3] [--past-from=YYYY-MM-DD]`

Reorganises `pmax` on `events_raw` and `visits` to add the current month plus `--ahead` months. Skips
tables that are not partitioned, so it is harmless with `DB_PARTITIONING=false`.

`--past-from` splits the `p_old` catch-all into months from that date, so imported history can also be
dropped a month at a time. One-off, not for cron.

### `retention:purge [--dry-run] [--force]`

Drops every partition entirely before the cutoff, then removes stragglers with chunked
`DELETE … LIMIT 5000`, then applies the shorter windows to the operational tables. It **refuses to
run** when a day before the cutoff is still in `rollup_dirty` — otherwise raw data would be deleted
before it had been aggregated. `--force` overrides; `--dry-run` only counts. Details:
[retention.md](retention.md).

### `geo:update [--force] [--url=<template>]`

Downloads `https://download.db-ip.com/free/dbip-country-lite-YYYY-MM.mmdb.gz` (override with `--url`
or the `GEO_DB_URL` environment variable, `%s` = `YYYY-MM`), falling back to the previous month when
the current one is not published yet, validates it by opening it, and swaps it in with `rename()`.
Without `--force` it does nothing when the current file is already from this month.

Needs outbound HTTPS. If the host has none, download the file elsewhere and copy it to `GEO_DB_PATH`.

### `queue:work [--max-time=55] [--batch=500] [--once]`

Only with `INGEST_MODE=queue` and `REDIS_DSN`. Pops drafts from the Redis list `an:ingest` and writes
them with the same code path as synchronous ingestion. `--max-time` keeps a cron-driven worker inside
its minute; `--once` processes what is queued and exits.

## Checking

```sh
php bin/analytics jobs:status          # last run of each job, rollup lag, dirty days, geo database age
php bin/analytics jobs:status --json
php bin/analytics health:check         # includes rollup lag, stale salts, geo age, recent failures
```

`GET /api/v1/admin/jobs` returns the same information to the dashboard's Jobs panel.

## What breaks if a job stops

| Job stops | Effect |
|---|---|
| `rollup:run` | reports stop updating (realtime keeps working); `rollup_dirty` grows; `health:check` warns after 30 minutes of lag; `retention:purge` starts refusing |
| `salt:rotate` | yesterday's salt survives in the table on a quiet site until the next event — a privacy regression, not an outage; `health:check` warns |
| `partitions:maintain` | new rows fall into `pmax`; ingestion is fine but retention degrades to chunked deletes |
| `retention:purge` | old data is never deleted; the database grows and the retention commitment is broken |
| `geo:update` | countries stay on the last database; after 45 days `health:check` warns |
| `queue:work` (queue mode) | **events are lost once Redis fills or is flushed** — this one is an outage |

## Related

[monitoring.md](monitoring.md) · [retention.md](retention.md) · [runbook.md](runbook.md) ·
[../architecture/reporting.md](../architecture/reporting.md)
