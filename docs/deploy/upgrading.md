# Upgrading

The rule that everything else follows: **a rollback moves code, never the database.** Rolling back is
a symlink move (tarball) or an image tag change (Docker); migrations are never reverted. Therefore
every release's schema must also work with the **previous** release's code.

## Expand / contract

A change that renames or removes something is split across two releases.

| Release | Does |
|---|---|
| N (expand) | add the new column/table/index, write to both, read from the old one; the old code still works because nothing it uses was touched |
| N+1 | read from the new one; the old code is no longer in play, but the old column is still there |
| N+2 (contract) | remove the old column |

Forbidden in a single release, and rejected by
`Analytics\Tests\Migrations\MigrationsTest::testMigrationsAreForwardOnlyAndExpandOnly`:

| Pattern | Why |
|---|---|
| `DROP COLUMN` | the previous release still selects it |
| `RENAME COLUMN` / `RENAME TABLE` / `RENAME TO` | same, and it is not atomic from the code's point of view |
| `DROP TABLE` | same |
| `MODIFY … NOT NULL` without a `DEFAULT` | the previous release inserts without that column |

The lint reads the migration source line by line. A line carrying the comment `contract-ok` is
skipped — that is the escape hatch for a genuine contract step, whose safety you have checked by hand:

```php
// contract-ok: legacy_column has not been read since 20260101T000000Z
$this->addSql('ALTER TABLE sites DROP COLUMN legacy_column');
```

Every migration must also be forward-only: `down()` throws `IrreversibleMigration`, and the test
asserts the class name appears in every migration file.

## What a release contains

The version is the UTC build timestamp. `BUILD_INFO.json` lists the PHP requirement, the required
extensions, the migration class names and the tracker hash; `REVISION` holds the commit. The deploy
console verifies all of it before switching.

## Tarball upgrade

```sh
# build machine
make package
deploy/manual/publish.sh site@host:/home/site/htdocs/stats.example.net
# or, on the server
./console deploy --dry-run        # verify and print the plan
./console deploy                  # verify → extract → preflight → warmup → migrate → switch → health
./console status
```

The order matters: **migrations run before the switch**, while the previous release is still serving.
That is exactly why the schema must be backward compatible — for the seconds between the migration and
the symlink swap, the old code is running against the new schema.

Useful flags:

| Flag | When |
|---|---|
| `--dry-run` | always, the first time you deploy a given package |
| `--backup` | before a release with a migration you have not run anywhere else |
| `--no-migrate` | deploying code you know contains no migration, or migrating separately |
| `--no-health` | the health endpoint is unreachable from the server itself |

## Docker upgrade

```sh
docker compose -f deploy/docker/compose.prod.yml pull
docker compose -f deploy/docker/compose.prod.yml up -d
```

`migrate` is a one-shot service; `app` depends on it having completed successfully and `web` on `app`
being healthy, so traffic never reaches code whose schema is not in place.

Pin `ANALYTICS_VERSION` rather than tracking `latest` if you want to control exactly when a schema
change happens.

## Rollback

```sh
./console rollback                 # to the previous release
./console rollback 20260917T190000Z
./console list                     # what is available
```

It only repoints `current`, so it works when the new release cannot boot at all — that is the whole
reason the console lives outside the releases.

**What the console refuses or warns about:**

| Situation | Behaviour |
|---|---|
| The database contains migrations the target release does not know (from `BUILD_INFO.json`) | warns and refuses; `--force` overrides |
| `current` exists but is not a symlink | refuses to deploy: "current exists but is not a symlink" |
| The release directory already exists | refuses to redeploy that timestamp; use `rollback`, or remove the directory |
| Checksum mismatch | refuses; the package is not extracted |
| An archive entry with an absolute path, `..`, or an escaping symlink | refuses |
| `REVISION` does not match `BUILD_INFO.json` | refuses |
| PHP version or extensions below the manifest | refuses |
| A failed migration | leaves `current` on the previous release, removes the temporary directory, records `failed` in the history |
| A failed health check with `auto_rollback` | switches `current` back, exits non-zero, records `rolled_back`; migrations stay applied |
| A cleanup that would remove the current or previous release | never removes them, whatever `--keep` says |
| A second deploy while one is running | blocks on the `flock` |

In Docker, a rollback is `ANALYTICS_VERSION=<previous>` plus `up -d`. There is no automatic check that
the previous image understands the current schema — the expand/contract rule is what protects you.

## Rolling back a schema change

There is no down migration. If a migration must be undone, write a **new forward migration** that
reverses it, subject to the same expand/contract rules, and deploy it as a normal release.

## Data migrations

Keep them out of the schema migration when they are long: a migration runs while the previous release
is serving and holds up the deploy. Prefer a console command run afterwards
(`rollup:rebuild`, `conversions:reattribute` are the existing examples of this shape).

## After upgrading

```sh
./console app migrations:status
./console app health:check
./console app jobs:status
curl -s https://stats.example.net/api/v1/health     # commit must match the new release
```

If a release changes how rollups are computed, rebuild them:

```sh
./console app rollup:rebuild --site=pk_XXXXXXXXXXXXXXXXXXXXX --from=2026-08-01 --to=2026-09-17
```

## Upgrading across several versions

Releases are cumulative: deploying the newest package applies every migration in between in order.
There is no need to step through intermediate versions. The nightly CI job `upgrade-migrations`
migrates from the previous tag plus seeded data to `HEAD` to keep that true.

## Compatibility notes

- **PHP**: ≥ 8.4.1 required (8.5 is exercised in CI). `app:preflight` refuses less, before the switch.
- **MySQL**: 8.4 expected; `app:preflight` warns below 8.0. Partitioning requires a build that
  supports it, otherwise deploy with `DB_PARTITIONING=false` from the start — changing it later does
  not convert existing tables.
- **Tracker**: the payload is versioned (`v`) and the server accepts the current version. An old
  cached tracker keeps working; the script is cached for 5 minutes with `stale-while-revalidate=600`.
- **Consent configuration**: unaffected by upgrades. Only publishing with `material_change` re-asks
  visitors.
- **API**: `docs/api/openapi.yaml` is the contract; the dashboard's generated types are checked
  against it in CI.

## Related

[tarball.md](tarball.md) · [docker.md](docker.md) · [../development/release.md](../development/release.md) ·
[../operations/runbook.md](../operations/runbook.md) · [../operations/backups.md](../operations/backups.md)
