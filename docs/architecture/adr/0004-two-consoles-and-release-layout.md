# 0004. Two consoles and a timestamped release layout

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

One target is a managed PHP host of the CloudPanel kind: a site user with SSH but no root, no Docker,
no Node, no systemd, a PHP-FPM pool that cannot be reloaded, and a web root that the panel points at a
fixed directory. Deployments must therefore be a file operation plus a database migration, and they
must be atomic — a visitor must never see a half-extracted release.

They must also be recoverable. If a release is broken (a fatal error at boot, a missing extension, a
bad `.env`), the tool that switches back to the previous release cannot itself live inside the broken
release or depend on its `vendor/` directory.

Finally, two very different operations must not be confused: "manage releases" and "run an application
command against the current release". Giving both to one binary invites running a migration with the
wrong release's code.

## Decision

Two separate consoles and a release directory layout.

```
<deploy_root>/
  console                    deploy manager: plain PHP, no extension, no Composer deps, chmod 750
  deploy.ini                 php_binary, keep_releases, keep_packages, health_url, auto_rollback,
                             backup_before_migrate, shared items
  packages/                  analytics-<TS>.tar.gz + .sha256
  releases/<TS>/             extracted releases
  shared/  .env (0600)  var/log/  var/storage/{geo,locks,backups}/
  current -> releases/<TS>   web root is current/public
  .deploy/  lock  history.jsonl
```

- `deploy/manual/console` manages packages, releases and the `current` symlink. Commands: `init`,
  `deploy`, `verify`, `list`, `status`, `rollback`, `cleanup`, `app`, `self-update`, `help`,
  `--version`. It lives outside `releases/`, has no dependencies and keeps working when the current
  release does not.
- `services/api/bin/analytics` is the per-release application console (migrations, jobs, users, sites,
  keys). `./console app <args>` passes through to `current/bin/analytics` using the configured
  `php_binary`.
- The version is the UTC build timestamp `YYYYMMDDTHHMMSSZ`; the commit is recorded beside it in
  `REVISION` and `BUILD_INFO.json`.
- The deploy flow is: take `flock`, resolve the package, verify the SHA-256 with `hash_equals`, reject
  archives with absolute paths, `..` or escaping symlinks, extract into `releases/.tmp-<TS>`, check
  `BUILD_INFO.json` against the PHP binary, link the shared items, run `app:preflight` and
  `cache:warmup`, optionally dump the database, run `migrations:migrate`, rename the temporary
  directory into place, then `symlink` + atomic `rename` over `current`, then the health check.
- On a failed health check with `auto_rollback`, `current` is pointed back at the previous release and
  the command exits non-zero. Migrations are never rolled back.

## Consequences

- A deploy is atomic from the web server's point of view: `current` is replaced by `rename()`, which
  is atomic on the same filesystem. Nothing is ever extracted into a live release directory.
- A rollback is a symlink move, so it takes milliseconds and works even when the new release cannot
  boot. Because the schema is not reverted, migrations must stay compatible with the previous release
  — see [../../deploy/upgrading.md](../../deploy/upgrading.md). The console warns and requires
  `--force` when the database contains migrations the target release does not know.
- Two consoles means two help texts and a naming rule to learn, in exchange for making
  "migrate with the wrong code" hard to do by accident.
- `self-update` copies `current/deploy/console` over the deploy manager, so the manager ships inside
  the package and upgrades with it.
- No FPM reload is available, so the vhost must pass `$realpath_root` to PHP; otherwise OPcache keeps
  serving the previous release's files through the unchanged `current/public` path. See
  [../../deploy/nginx.md](../../deploy/nginx.md).
- Pinned by the PHPUnit suite in `deploy/manual/tests` (`DeployFlowTest`, `PackageVerificationTest`,
  `RollbackCleanupTest`, `HealthCheckTest`, `OperationsTest`) and by the smoke container in
  `deploy/manual/smoke`.
