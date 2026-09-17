# Runbook

Common incidents, what they look like and what to do. Start every investigation with:

```sh
php bin/analytics health:check          # or: ./console app health:check
php bin/analytics jobs:status
./console status                        # tarball deployments
```

---

## Rollup lag: reports are stale

**Symptoms.** The dashboard shows old numbers while Realtime works. `health:check` reports
`rollup_lag: warn`. `jobs:status` shows a large `rollup_lag_minutes` or a growing `dirty_days`.
`retention:purge` starts refusing to run.

**Diagnose.**

```sh
php bin/analytics jobs:status
# SELECT COUNT(*), MIN(first_marked_at) FROM rollup_dirty;
# SELECT * FROM job_runs WHERE job = 'rollup:run' ORDER BY started_at DESC LIMIT 5;
```

| `last_status` | Meaning |
|---|---|
| nothing recent | cron is not firing at all |
| `skipped` repeatedly | a previous run still holds the lock — either it is genuinely long, or a stale lock |
| `failed` | read `last_message` and `job_runs.message` |
| `succeeded` but the lag grows | more dirty days arrive than 500 per run can absorb |

**Fix.**

1. Cron not firing: check the crontab, the PHP binary path, and that `current` exists. Run it by hand
   (`php bin/analytics rollup:run`) and read the output.
2. Backlog: raise the batch and run repeatedly until `dirty_days` stops falling.
   ```sh
   php bin/analytics rollup:run --limit=2000
   ```
   Consider running it every minute while catching up.
3. Stale lock (the process was killed): with Redis, `DEL an:job:rollup:run`-style keys are managed by
   `symfony/lock` — the simplest safe action is to wait for the 3600 s TTL. With the file lock, remove
   the stale file under `STORAGE_DIR/locks` **only after confirming no `rollup:run` process is
   running**.
4. One site is pathological: `php bin/analytics rollup:run --site=pk_… --limit=50` to isolate it, and
   check whether it is a `dev:seed`-sized backfill.

**Prevent.** Alert on `rollup_lag_minutes > 30`. See [monitoring.md](monitoring.md).

---

## Queue backlog (queue mode only)

**Symptoms.** `INGEST_MODE=queue`; new events do not appear even in Realtime. Redis memory grows.

**Diagnose.**

```sh
redis-cli LLEN an:ingest             # keys are prefixed "an:"
php bin/analytics jobs:status
docker compose -f deploy/docker/compose.prod.yml ps worker     # Docker
```

**Fix.**

1. Drain by hand and watch the length fall:
   ```sh
   php bin/analytics queue:work --once --batch=1000
   php bin/analytics queue:work --max-time=300 --batch=1000
   ```
2. Not running: check the `queue` profile (Docker) or the cron line (tarball — it is commented out by
   default and must be enabled when switching to queue mode).
3. The worker fails on every batch: run it in the foreground without `-q` and read the error. Malformed
   entries are dropped individually, so a persistent failure is a database or configuration problem.
4. Emergency: switch `INGEST_MODE=sync` and redeploy. New events are written in-request; then drain
   the remaining queue with `queue:work --once` until `LLEN` is 0.

**Risk.** The queue is a Redis list with no persistence in the packaged configuration
(`--save "" --appendonly no`). A Redis restart or eviction **loses queued events**. That is the main
reason to alert on queue depth.

---

## A deploy failed

**Symptoms.** `./console deploy` exited non-zero; the site may be on the old or the new release.

**What the console guarantees.** Anything failing *before* the switch leaves `current` untouched and
removes the temporary directory. A failed health check with `auto_rollback` switches `current` back.
Migrations are never rolled back.

**Diagnose.**

```sh
./console status
./console list
tail -n 50 .deploy/history.jsonl
tail -n 100 shared/var/log/*.log
./console app app:preflight
./console app migrations:status
```

**Common causes.**

| Message | Cause |
|---|---|
| checksum mismatch | truncated upload; re-copy the `.tar.gz` **and** its `.sha256` |
| `REVISION` / manifest mismatch, unsafe archive entry | a hand-modified or repacked package; rebuild it |
| PHP version or extension missing | `php_binary` points at the wrong PHP; fix `deploy.ini` |
| preflight failure | a missing `.env` value or an unwritable directory; the output names it |
| migration failure | read the SQL error; fix and redeploy. The previous release is still live |
| health check failed | usually `$realpath_root` missing from the vhost, so the old code is still served — see [../deploy/nginx.md](../deploy/nginx.md) |
| `current exists but is not a symlink` | someone extracted a release over `current`; move it aside and redeploy |
| `Release … already exists` | that timestamp was deployed before; `./console rollback <TS>` or remove the directory |

**Fix.** Correct the cause and `./console deploy` again. If the new release is live and broken:

```sh
./console rollback
```

It only moves the symlink, so it works even with a completely broken release. If the database has
migrations the target does not know, the console warns and requires `--force` — review
[../deploy/upgrading.md](../deploy/upgrading.md) before using it.

---

## Database full or nearly full

**Symptoms.** Writes fail; `health:check` reports `disk: warn`; the MySQL error log shows disk-full
errors.

**Diagnose.**

```sql
SELECT table_name, ROUND((data_length + index_length)/1024/1024) AS mb
  FROM information_schema.tables WHERE table_schema = DATABASE()
 ORDER BY data_length + index_length DESC LIMIT 10;

SELECT table_name, partition_name, table_rows,
       ROUND((data_length + index_length)/1024/1024) AS mb
  FROM information_schema.partitions
 WHERE table_schema = DATABASE() AND table_name IN ('events_raw','visits')
 ORDER BY partition_name;
```

**Fix, in order.**

1. Make retention work. It refuses while days before the cutoff are un-rolled-up, which is the usual
   reason it has silently done nothing for months:
   ```sh
   php bin/analytics retention:purge --dry-run
   php bin/analytics rollup:run --limit=2000      # repeat until dirty_days is 0
   php bin/analytics retention:purge
   ```
2. Confirm partitioning is actually on. If `information_schema.partitions` shows a single NULL
   partition, the tables were created with `DB_PARTITIONING=false` and retention is doing chunked
   deletes — slow, and it does not return space to the filesystem.
3. Free space cheaply: old backups in `shared/var/storage/backups/`, old logs in `shared/var/log/`,
   old releases (`./console cleanup --keep=2 --packages`).
4. Shorten retention if the volume is simply too high: set `RETENTION_MONTHS` lower and run
   `retention:purge`. Rollups are unaffected, so historic charts survive.
5. Reclaim space after large chunked deletes (`OPTIMIZE TABLE`) only during a quiet period — it
   rebuilds the table.

**Prevent.** Alert on free space, chart table sizes, and make sure `partitions:maintain` runs so
retention can drop partitions instead of deleting rows.

---

## A bad consent configuration was published

**Symptoms.** The banner shows wrong text, an unreadable theme, the wrong language, or every visitor
is being asked again.

**Remember.** Published revisions are immutable and `consent_version` only increases when you publish
with `material_change: true`. You cannot "unpublish"; you publish a corrected revision.

**Fix.**

1. Settings → Consent, correct the draft, check the live preview.
2. Publish with **`material_change: false`** if the correction is cosmetic (a typo, a colour, a link).
   `consent_version` stays the same, so visitors who already decided are **not** asked again.
3. Publish with `material_change: true` only if the *meaning* changed and consent must genuinely be
   collected afresh.
4. Propagation takes up to about ten minutes (the script is `max-age=300` with
   `stale-while-revalidate=600`; publishing clears the server-side cache immediately).

**If `consent_version` was bumped by mistake**, everyone is asked again. There is no way to lower it:
the field only increases, and a visitor's cookie with a lower version is treated as unknown. Publish
the correct text with `material_change: false` and let the re-asking pass. The consent report shows
the size of the effect.

**If the theme fails the contrast check**, the API refuses to save it (`422 validation_failed` naming
`theme.fg` or `theme.acf`) — that is by design; pick compliant colours.

**Verify.**

```sh
curl -s https://stats.example.net/t/pk_XXXXXXXXXXXXXXXXXXXXX.js | head -c 400
```

The `window.__an_cfg` block shows the published `consent.v`, texts and theme.

---

## No data is arriving from a site

```sh
curl -sI https://stats.example.net/t/pk_XXXXXXXXXXXXXXXXXXXXX.js
curl -s -X POST https://stats.example.net/t/e \
  -H 'Origin: https://www.example.com' -H 'Content-Type: text/plain' \
  -d '{"v":1,"k":"pk_XXXXXXXXXXXXXXXXXXXXX","l":"b","e":[]}' -i | head -5
```

| Result | Cause |
|---|---|
| `404` on the script | wrong public key, or the site is archived |
| `403 origin_not_allowed` | the host is not under the site's domains |
| `429 rate_limited` | over the `collect`/`collect_site` limit |
| `202` but no rows | bot filter, `excluded_paths`, `DNT`, or a host mismatch in the payload URL |
| Nothing in the browser at all | `navigator.webdriver`, a blocker, or a CSP that forbids the script |

`SELECT * FROM events_raw WHERE site_id = ? ORDER BY id DESC LIMIT 5` tells you whether the problem is
before or after ingestion. Remember Realtime is raw and immediate, while every other report waits for
`rollup:run`.

---

## Every visitor shares one visitor hash

**Symptom.** Visitors ≈ 1 per day, all traffic from one country.

**Cause.** A proxy or load balancer sits in front and `TRUSTED_PROXIES` does not list it, so the
proxy's own address is used for every request.

**Fix.** Add the proxy's addresses to `TRUSTED_PROXIES` and restart PHP. Historic rows cannot be
repaired — the salt for those days is gone.

---

## A site setting changed and historic numbers look wrong

**Symptom.** After editing a site, recent days show the new numbers while older days still show the
old ones — typically `contacts` after changing `content_contact_events`, or revenue after changing
`currency`.

**Cause.** Those settings are inputs to the stored daily rollups. Saving the site bumps the site's
`rollup_version`, so every cached report is recomputed immediately, but the rollup rows themselves
still hold the numbers that were correct under the old setting. Days that happen to be marked dirty
afterwards are rebuilt; the rest are not.

**Fix.** Rebuild the affected range once, oldest day you care about first:

```bash
./console app rollup:rebuild --site=pk_XXXXXXXXXXXXXXXXXXXXX --from=2025-09-01 --to=2026-09-17
```

The same applies after a time-zone change, where `--recompute-days` is also needed so rows move to
the day they now belong to.

---

## Reports are slow

1. `meta.source` in the response: `raw` means the planner could not use a rollup (a filter the rollup
   does not cover, or `interval=hour`).
2. `meta.cache`: `miss` every time means `rollup_version` is being bumped constantly — that is
   `rollup:run` processing a backlog.
3. `EXPLAIN` the query. `Analytics\Tests\Integration\Reporting\QueryPlanTest` asserts index use and
   partition pruning; a regression there is a bug, not an operational issue.
4. Check that `partitions:maintain` has been running: without partitions, range queries scan the whole
   table.

---

## Escalation

A suspected security incident follows [incident-response.md](incident-response.md), not this page.

## Related

[monitoring.md](monitoring.md) · [cron.md](cron.md) · [backups.md](backups.md) ·
[retention.md](retention.md) · [../deploy/upgrading.md](../deploy/upgrading.md)
