# Tarball deployment

For hosts with SSH but without Docker, Node or root — the managed PHP hosting case. A release is a
timestamped tar archive, extracted next to the previous ones, with an atomically switched `current`
symlink. The rationale is in
[../architecture/adr/0004-two-consoles-and-release-layout.md](../architecture/adr/0004-two-consoles-and-release-layout.md);
the host-side setup is [managed-php-hosts.md](managed-php-hosts.md).

## Building a package

```sh
make package                                   # refuses a dirty working tree
./deploy/manual/build.sh --allow-dirty --output dist
```

`deploy/manual/build.sh` needs only **git and docker buildx** on the build machine — no PHP, no Node.
It refuses an uncommitted tree unless `--allow-dirty`, then runs
`docker buildx build -f deploy/docker/Dockerfile --target package --output type=local,dest=dist`,
passing `BUILD_TS`, `COMMIT`, `COMMIT_DATE` and `COMMIT_TIME` as build arguments. Options:
`--allow-dirty`, `--output DIR` (default `dist`), `-h`.

Output:

```
dist/analytics-20260917T190000Z.tar.gz
dist/analytics-20260917T190000Z.tar.gz.sha256      # sha256sum format
```

The version is the **UTC build timestamp**. The archive is reproducible: entries sorted, numeric
owners, the commit time as mtime, `gzip -n`.

## Package contents

No wrapper directory — the archive extracts into the release directory itself.

```
BUILD_INFO.json   REVISION   LICENSE   NOTICE   .env.example   composer.json
bin/analytics
config/csp.php
migrations/
resources/                  tracker bundle, referrer lists
src/
vendor/
public/  index.php  index.html  200.html  _nuxt/  _fonts/  favicon.ico
var/cache/                  # var/log and var/storage become links into shared/ at deploy time
deploy/console              # the deploy manager itself, for self-update
deploy/examples/
```

Tests, PHPUnit/PHPStan/Rector/Deptrac/CS-Fixer configuration and any `.env` are never packaged.

`BUILD_INFO.json` is the manifest the console verifies against:

```json
{
  "name": "analytics",
  "version": "20260917T190000Z",
  "commit": "ac4d1fc60ddf3486e7e9d9ae5d8df976d02bc0fa",
  "commit_date": "2026-09-17T20:15:29+02:00",
  "built_at": "2026-09-17T18:37:11Z",
  "php": ">=8.4.1",
  "extensions": ["intl", "json", "mbstring", "pdo", "pdo_mysql", "sodium"],
  "migrations": ["Version20260917000001", "Version20260917000002"],
  "tracker_sha256": "8e38f94d…"
}
```

`php` and `extensions` come from `services/api/composer.json`, the migration list from
`migrations/Version*.php` (the console compares it on rollback) and `tracker_sha256` from the built
tracker. `REVISION` holds the commit and must agree with the manifest.

## Server layout

```
<deploy_root>/                 e.g. /home/site/htdocs/stats.example.net
├── console                    deploy manager (PHP, no extension, chmod 750)
├── deploy.ini                 configuration
├── packages/                  analytics-<TS>.tar.gz + .sha256
├── releases/<TS>/
├── shared/  .env (0600)  var/log/  var/storage/{geo,locks,backups}/
├── current -> releases/<TS>   web root = current/public
└── .deploy/  lock  history.jsonl
```

Permissions: the site user owns everything (the PHP-FPM pool runs as that user); directories 0750,
files 0640, `.env` 0600, `console` 0750. No sudo is needed at any point.

## `deploy.ini`

Written by `./console init` from `deploy/manual/deploy.ini.example`.

| Key | Default | Meaning |
|---|---|---|
| `php_binary` | `""` | PHP CLI used for `bin/analytics` and the runtime check. Empty = the PHP running `./console`. On panels with several versions, set it explicitly, e.g. `/usr/bin/php8.4` |
| `keep_releases` | `5` | releases kept after a deploy; current and previous are never removed |
| `keep_packages` | `3` | packages kept |
| `health_url` | — | must return `200` with a JSON `commit` equal to the deployed commit. Empty disables the check |
| `health_tries` | `5` | attempts |
| `health_interval` | `10` | seconds between attempts |
| `health_timeout` | `10` | per-request timeout |
| `auto_rollback` | `true` | on a failed health check, point `current` back and exit non-zero |
| `backup_before_migrate` | `false` | dump the database before migrating |
| `mysqldump_binary` | `mysqldump` | |
| `shared[]` | `.env`, `var/log`, `var/storage` | items symlinked into every release |

## Console commands

```
./console init                          create the directories and deploy.ini (idempotent)
./console deploy [package] [options]     verify, prepare and switch (default: newest package)
      --no-migrate     skip the migrations
      --backup         dump the database before migrating
      --no-health      skip the health check after switching
      --dry-run        verify and print the plan, change nothing
./console verify <package>               checksum, archive safety and manifest only
./console list                           releases (current/previous marked) and packages
./console status [--no-health]           current release, health, recent history
./console rollback [release] [--force]   point current at the previous (or given) release
      --no-health
./console cleanup [--keep=N] [--packages]
./console app <args…>                    run current/bin/analytics <args…>
./console self-update                    replace this console with current/deploy/console
./console help
./console --version
```

Exit codes: `0` success, `1` failure, `2` usage error. A package may be named by file name, by its
timestamp, or by a path.

## Deploy flow

1. Take the `flock` on `.deploy/lock`. A second deploy waits rather than interleaving.
2. Resolve the package (`^analytics-\d{8}T\d{6}Z\.tar\.gz$`, newest by default) and verify its
   SHA-256 with `hash_equals`.
3. Refuse the archive if any entry has an absolute path, contains `..`, or is a symlink escaping the
   release. Extract into `releases/.tmp-<TS>`.
4. Read `BUILD_INFO.json`; check the PHP version and extensions with `php_binary`, and that `REVISION`
   matches the manifest commit.
5. Link the shared items with relative symlinks, replacing whatever the package had at those paths.
6. Run `bin/analytics app:preflight`, then `bin/analytics cache:warmup`.
7. Optionally `mysqldump --single-transaction`, **excluding `daily_salts`**, into
   `shared/var/storage/backups/<TS>.sql.gz`.
8. Run `bin/analytics migrations:migrate --no-interaction --allow-no-migration`.
9. Rename the temporary directory to `releases/<TS>`, then `symlink()` + `rename()` over `current` —
   atomic, so a concurrent request never sees a missing `current`.
10. Health check: up to `health_tries` requests to `health_url`, expecting `200` and a matching
    `commit`. On failure with `auto_rollback`, switch `current` back and exit `1`. **Migrations are
    never rolled back.**
11. Append to `.deploy/history.jsonl` and clean up old releases and packages.

Anything failing before step 9 leaves `current` untouched and removes the temporary directory.

## First installation

```sh
ssh site@host
cd /home/site/htdocs/stats.example.net

# 1. the console: copy deploy/manual/console from the repository, or extract it from a package
tar -xzf packages/analytics-20260917T190000Z.tar.gz -O ./deploy/console > console
chmod 750 console

# 2. directories, deploy.ini and shared/.env (from the newest package's .env.example)
./console init

# 3. fill in the environment (see ../architecture/overview.md#configuration)
php8.4 -r 'require "…";'    # or run secrets:generate after the first deploy
nano shared/.env            # APP_URL, DATABASE_URL, APP_SECRET, APP_ENCRYPTION_KEYS
chmod 600 shared/.env

# 4. first deploy
./console deploy
./console status

# 5. first administrator and first site
./console app user:create-admin --email=admin@example.com
./console app site:create --name="Example" --domain='*.example.com' --timezone=Europe/Rome
```

Secrets can be generated with `./console app secrets:generate` once a release exists, or with
`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` before that.

Then set up the web server ([nginx.md](nginx.md)) and cron
([../operations/cron.md](../operations/cron.md)).

## Routine deploys

From the build machine:

```sh
make package
deploy/manual/publish.sh site@host:/home/site/htdocs/stats.example.net
# copies the package and its .sha256 into packages/ and runs ./console deploy there
# pass deploy options after --, e.g.  … -- --backup
```

Or by hand:

```sh
scp dist/analytics-*.tar.gz dist/analytics-*.tar.gz.sha256 site@host:/…/packages/
ssh site@host 'cd /…/stats.example.net && ./console deploy'
```

Check first with `./console deploy --dry-run`.

## Rollback

```sh
./console rollback              # to the previous release
./console rollback 20260917T190000Z
```

It only moves the symlink, so it works even when the current release cannot boot. If the database
contains migrations the target release does not know, the console warns and requires `--force`. See
[upgrading.md](upgrading.md).

## Verifying a package by hand

```sh
tar -xzOf dist/analytics-*.tar.gz ./BUILD_INFO.json
tar -tzf dist/analytics-*.tar.gz | head
(cd dist && sha256sum -c ./*.sha256)
./console verify analytics-20260917T190000Z.tar.gz
```

`make test-smoke` runs the whole flow — init, deploy, health, second deploy, rollback, list, status,
cleanup — inside a container that imitates a managed PHP host.

## Related

[managed-php-hosts.md](managed-php-hosts.md) · [nginx.md](nginx.md) · [upgrading.md](upgrading.md) ·
[docker.md](docker.md) · [../development/release.md](../development/release.md) ·
[../operations/runbook.md](../operations/runbook.md)
