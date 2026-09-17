# Backups

## What to back up

| Item | Why | How often |
|---|---|---|
| The MySQL database, **excluding `daily_salts`** | everything: configuration, users, events, rollups | daily, plus before every migration |
| `shared/.env` (tarball) or the compose `env_file` | `APP_SECRET` and `APP_ENCRYPTION_KEYS` — **without them the database is partly unusable** | on every change, stored separately from the database dump |
| `shared/var/storage/` (tarball) or the `storage` volume (Docker) | the geo database and lock files | weekly, or not at all — both are reproducible |
| The release packages in `packages/` | lets you roll back to a release you no longer have | keep a few; `keep_packages` in `deploy.ini` already does |

Not worth backing up: `var/cache` (rebuilt by `cache:warmup`), `var/log`, `releases/` (rebuildable
from a package), the Redis instance (a cache — except in queue mode, see below).

## Why `daily_salts` is excluded

`daily_salts` holds the secret that keys the base-level `visitor_hash`:

```
sodium_crypto_generichash(site_id ‖ shortened IP ‖ "\0" ‖ User-Agent, salt_of_today, 16)
```

The whole point of the base level is that the salt exists for one UTC day and is then destroyed, so a
hash cannot be recomputed or linked across days even by someone holding the database
([../privacy/two-levels.md](../privacy/two-levels.md), LR-1 in
[../privacy/legal-review-points.md](../privacy/legal-review-points.md)).

A backup that contains the salt breaks that. Anyone with the backup and a candidate address plus
User-Agent can recompute the hashes for that day and confirm whether a specific person visited — turning
an anonymised dataset back into a linkable one, for as long as the backup is kept, which is usually far
longer than a day.

So: **never include `daily_salts` in a dump, an archive or a replica used for analysis.** The table
is regenerated automatically — the first event of the day creates it, and `salt:rotate` guarantees it.
Restoring without it is entirely safe.

`retention:purge` does not manage this table; the deletion happens on every salt read and in
`salt:rotate`.

## Taking a dump

The deploy console does the right thing already. With `backup_before_migrate = true` in `deploy.ini`,
or `./console deploy --backup`, it runs:

```
mysqldump --defaults-extra-file=<temporary 0600 file> \
          --single-transaction --quick --no-tablespaces \
          --ignore-table=<database>.daily_salts <database>
```

writing a gzipped file into `shared/var/storage/backups/`. Credentials are taken from `shared/.env`
(`DATABASE_URL`, or `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`) and passed through a
temporary `--defaults-extra-file` rather than the command line, so they never appear in the process
list.

By hand:

```sh
mysqldump --single-transaction --quick --no-tablespaces \
          --ignore-table=analytics.daily_salts \
          analytics | gzip -c > analytics-$(date -u +%Y%m%dT%H%M%SZ).sql.gz
```

In Docker:

```sh
docker compose -f deploy/docker/compose.prod.yml exec -T mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --quick --no-tablespaces \
  --ignore-table=analytics.daily_salts analytics | gzip -c > analytics.sql.gz
```

`--single-transaction` gives a consistent snapshot of the InnoDB tables without locking writers.
`--no-tablespaces` avoids needing the `PROCESS` privilege.

### Partitions

`mysqldump` emits the `PARTITION BY` clause as part of the `CREATE TABLE`, so a restore recreates the
partition layout **as it was at dump time**. Run `partitions:maintain` after restoring an old dump,
otherwise today's rows land in `pmax` (which works, but cannot be dropped cheaply later).

## Restoring

```sh
# 1. new database
mysql -e 'CREATE DATABASE analytics CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;'

# 2. the dump
gunzip -c analytics-20260917T190000Z.sql.gz | mysql analytics

# 3. the environment file with the SAME APP_SECRET and APP_ENCRYPTION_KEYS
cp /secure/location/.env shared/.env && chmod 600 shared/.env

# 4. bring the schema and the operational state up to date
./console app migrations:migrate --no-interaction --allow-no-migration
./console app partitions:maintain
./console app salt:rotate            # recreates today's salt
./console app cache:clear
./console app health:check
```

`daily_salts` will be empty, which is correct — it refills itself.

### What breaks with the wrong secrets

| Secret | If it differs from the one in use when the data was written |
|---|---|
| `APP_ENCRYPTION_KEYS` (missing key id) | stored TOTP secrets cannot be decrypted; those users must re-enrol (`user:reset-2fa`) |
| `APP_SECRET` | `customer_ref` hashes stop matching the old ones, so attribution by customer reference no longer links to historic conversions. Session and invitation tokens are invalidated (they are hashed independently, but the derivation context changes) |

Keep the environment file in a password manager or a secrets store, **separately from the database
dump** — together they are equivalent to the live system. See [key-rotation.md](key-rotation.md).

## Retention of the backups themselves

A dump contains 13 months of visit-level data. It inherits the same retention commitment, so a backup
kept for three years quietly extends your retention period to three years. Decide a backup retention
(30–90 days is typical), enforce it, and encrypt the backups at rest.

The console does not rotate `shared/var/storage/backups/` — that is your job:

```sh
find shared/var/storage/backups -name '*.sql.gz' -mtime +30 -delete
```

## Redis

Normally a cache: losing it costs a cold cache and reset rate-limit counters. Nothing needs backing
up.

**In queue mode it is different**: the `an:ingest` list holds events that have not been written yet,
and the packaged configuration runs Redis without persistence (`--save "" --appendonly no`). A restart
loses them. If that matters, enable persistence or accept the loss and keep the queue short — see
[runbook.md](runbook.md#queue-backlog-queue-mode-only).

## Testing the restore

Untested backups are not backups. Once a quarter: restore the newest dump into a scratch database,
point a throwaway installation at it, and check that

- `migrations:status` reports nothing pending,
- `health:check` is `ok`,
- a report for a past month matches production,
- an administrator can sign in.

## Checklist

- [ ] Daily dump, `--single-transaction`, **`--ignore-table=…​.daily_salts`**
- [ ] Dumps encrypted at rest and kept off the application server
- [ ] `.env` backed up separately, with the current `APP_SECRET` and `APP_ENCRYPTION_KEYS`
- [ ] A dump taken before every migration (`backup_before_migrate = true`)
- [ ] Backup retention decided and enforced
- [ ] Restore rehearsed within the last quarter

## Related

[key-rotation.md](key-rotation.md) · [retention.md](retention.md) · [runbook.md](runbook.md) ·
[../privacy/data-inventory.md](../privacy/data-inventory.md)
