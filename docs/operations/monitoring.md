# Monitoring

Three sources: the health endpoint, `jobs:status`, and the database itself.

## Health endpoint

```
GET /api/v1/health          # public, no authentication, Cache-Control: no-store
```

```json
{ "status": "ok", "version": "20260917T190000Z", "commit": "ac4d1fc6…" }
```

`status` is `ok`, `warn` or `fail`. **HTTP 200 for `ok` and `warn`, 503 for `fail`.** Nothing else is
exposed — it is safe to point an uptime check at it, and the deploy console uses it to confirm a
release (it compares `commit`).

The detailed version needs a global admin session:

```
GET /api/v1/admin/health
```

```json
{
  "data": {
    "status": "warn",
    "checks": {
      "database":   { "status": "ok",   "detail": "connected" },
      "migrations": { "status": "ok",   "detail": "0 pending" },
      "rollup_lag": { "status": "ok",   "detail": "3 min" },
      "salt":       { "status": "ok",   "detail": "no stale salts" },
      "jobs":       { "status": "ok",   "detail": "no failures in 24h" },
      "geo_db":     { "status": "warn", "detail": "52 days old" },
      "disk":       { "status": "ok",   "detail": "18.4 GB free" }
    },
    "version": "20260917T190000Z",
    "commit": "ac4d1fc6…"
  }
}
```

Same checks from the console:

```sh
php bin/analytics health:check            # human readable, exit 1 on fail
php bin/analytics health:check --json
php bin/analytics health:check --strict   # exit 1 on warn as well
```

### What each check means

| Check | `ok` | `warn` | `fail` |
|---|---|---|---|
| `database` | a `SELECT 1` succeeded | — | unreachable. **The remaining checks are skipped** and the overall status is `fail` |
| `migrations` | no pending migration | — | pending migrations, or the status could not be read |
| `rollup_lag` | the oldest dirty day is < 30 min old | ≥ 30 min (`HealthChecker::ROLLUP_LAG_WARN_MINUTES`) | — |
| `salt` | no `daily_salts` row before today | stale salts present; run `salt:rotate` | — |
| `jobs` | no failed `job_runs` row in the last 24 h | a job failed; the name and time are in `detail` | — |
| `geo_db` | the file exists and is < 45 days old | older than 45 days (`GEO_MAX_AGE_DAYS`), or missing | — |
| `disk` | ≥ 1 GB free on `STORAGE_DIR` | < 1 GB free | — |

The overall status is `fail` if any check fails, `warn` if any warns, otherwise `ok`.

`rollup_lag` and `salt` are only evaluated when `migrations` is `ok`, because the tables may not exist
yet on a half-migrated database.

## Jobs status

```sh
php bin/analytics jobs:status
php bin/analytics jobs:status --json
```

```json
{
  "jobs": [
    { "job": "rollup:run", "last_started_at": "2026-09-17T11:55:00+00:00",
      "last_status": "succeeded", "last_message": null, "last_duration_ms": 412 }
  ],
  "rollup_lag_minutes": 3,
  "dirty_days": 2,
  "geo_db_age_days": 12,
  "pending_consent_drafts": [{ "site_id": 1, "site_name": "Example" }]
}
```

Jobs tracked: `rollup:run`, `rollup:rebuild`, `salt:rotate`, `retention:purge`,
`partitions:maintain`, `geo:update`, `conversions:reattribute`. `last_status` is `succeeded`, `failed`
or `skipped` (another run held the lock).

The same payload backs `GET /api/v1/admin/jobs` and the dashboard's Jobs panel.

## What to alert on

### Page someone

| Condition | Why |
|---|---|
| `GET /api/v1/health` returns 503, or does not answer | the service is down or the database is unreachable |
| `checks.migrations` is `fail` for more than a few minutes | a deploy left the schema behind the code |
| Queue length (`LLEN an:ingest`) grows for more than 15 minutes, in queue mode | the worker is not running; events are at risk |
| Free disk below 2 GB | the database and the logs are about to stop writing |

### Ticket, not a page

| Condition | Why |
|---|---|
| `rollup_lag_minutes` > 30 | `rollup:run` is not keeping up or is not running |
| `dirty_days` growing day over day | same, and `retention:purge` will start refusing |
| Any job with `last_status: failed` | look at `last_message` and at `job_runs` |
| `rollup:run` `last_started_at` older than 15 minutes | cron is not firing |
| `salt` check warning | `salt:rotate` is not running — a privacy invariant |
| `geo_db_age_days` > 45 | `geo:update` is failing, probably no outbound HTTPS |
| Sustained `429` on `/t/e` | a site is over its rate limit, or a bot is hammering the endpoint |
| Sustained `403 origin_not_allowed` | a tracked host is missing from the site's domains |
| `pending_consent_drafts` not empty for a long time | someone forgot to publish |

### Useful probes

```sh
# uptime check
curl -fsS https://stats.example.net/api/v1/health >/dev/null

# cron-driven, exits non-zero on a failure
php bin/analytics health:check --json > /var/tmp/analytics-health.json

# queue depth (queue mode)
redis-cli -n 0 LLEN an:ingest      # keys are prefixed "an:"

# rollup backlog straight from the database
SELECT COUNT(*) AS dirty_days, MIN(first_marked_at) AS oldest FROM rollup_dirty;

# failed jobs in the last day
SELECT job, started_at, message FROM job_runs
 WHERE status = 'failed' AND started_at > NOW() - INTERVAL 1 DAY ORDER BY started_at DESC;

# table sizes
SELECT table_name, ROUND((data_length + index_length) / 1024 / 1024) AS mb
  FROM information_schema.tables WHERE table_schema = DATABASE()
 ORDER BY data_length + index_length DESC LIMIT 10;
```

## Logs

Monolog writes to `LOG_DIR` (`shared/var/log` on a tarball deploy, the `logs` volume in Docker) at
`LOG_LEVEL` (`warning` in production). Every record passes `IpScrubbingProcessor`, which masks
anything that looks like an IPv4 or IPv6 address, so log shipping does not leak addresses.

Each request carries an `X-Request-Id` (echoed from the client when it matches
`[A-Za-z0-9._-]{8,64}`, otherwise generated), and the same id appears in the problem response, so a
user-reported error can be found in the log.

The web server's access log for `/t/` must be anonymised or disabled — see
[../deploy/nginx.md](../deploy/nginx.md#anonymised-logs).

## Metrics worth charting

There is no metrics endpoint. What is worth graphing from the database:

| Query | Shows |
|---|---|
| `SELECT COUNT(*) FROM rollup_dirty` | rollup backlog |
| `SELECT job, AVG(TIMESTAMPDIFF(MICROSECOND, started_at, finished_at)/1000) FROM job_runs WHERE status='succeeded' GROUP BY job` | job durations |
| `SELECT local_day, COUNT(*) FROM events_raw WHERE local_day >= CURDATE() - INTERVAL 7 DAY GROUP BY local_day` | ingestion volume |
| table sizes (above) | growth, and whether retention is working |

## Related

[cron.md](cron.md) · [runbook.md](runbook.md) · [incident-response.md](incident-response.md) ·
[retention.md](retention.md)
