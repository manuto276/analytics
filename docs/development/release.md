# Releasing

Two artefacts come out of the same `deploy/docker/Dockerfile`:

1. **a tarball** `analytics-<TS>.tar.gz` (+ `.sha256`) for managed PHP hosts,
   deployed with `deploy/manual/console`;
2. **container images** `ghcr.io/manuto276/analytics-php`, `-cron` and `-web`
   for the Docker deployment (`deploy/docker/compose.prod.yml`).

Both are built from the `release-tree` stage, so what a tarball contains and
what the images contain is byte-for-byte the same application.

## Version

The version is the **UTC build timestamp**, `YYYYMMDDTHHMMSSZ`. The commit is
recorded next to it, in `REVISION` and in `BUILD_INFO.json`:

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

`php` and `extensions` come from `services/api/composer.json`, the migration
list from `migrations/Version*.php` (the console compares it on rollback) and
`tracker_sha256` from the built tracker. The deploy console refuses a package
whose `REVISION` and `commit` disagree, or whose PHP runtime does not satisfy
the manifest.

## Building a package

```bash
make package                      # refuses a dirty tree
./deploy/manual/build.sh --allow-dirty --output dist
```

The host needs neither PHP nor Node:

```
node-build    pnpm install + tracker build + nuxt generate + CSP hashes
vendor        composer install --no-dev --classmap-authoritative
release-tree  the layout of plan §10.1 + BUILD_INFO.json + REVISION
tarball       tar --sort=name --owner=0 --group=0 --numeric-owner --mtime=@<commit time> | gzip -n
package       FROM scratch → dist/analytics-<TS>.tar.gz
```

Contents (no wrapper directory):

```
BUILD_INFO.json  REVISION  LICENSE  NOTICE  .env.example  composer.json
bin/analytics  config/csp.php  migrations/  resources/  src/  vendor/
public/  index.php  index.html  200.html  _nuxt/  _fonts/  favicon.ico
var/cache/                      # var/log and var/storage become shared/ links at deploy
deploy/console  deploy/examples/
```

Tests, PHPUnit/PHPStan/Rector/Deptrac/CS-Fixer configuration and `.env` are
never packaged. The archive is reproducible: sorted entries, numeric owners, the
commit time as mtime and `gzip -n`, so the same commit yields the same bytes
(given the same dependency versions).

Check a package by hand:

```bash
tar -xzOf dist/analytics-*.tar.gz ./BUILD_INFO.json
tar -tzf dist/analytics-*.tar.gz | head
(cd dist && sha256sum -c ./*.sha256)
make test-smoke        # init → deploy → health → deploy → rollback → cleanup
```

## Shipping the package

```bash
deploy/manual/publish.sh user@host:/home/site/htdocs/stats.example.net dist/analytics-<TS>.tar.gz
# on the server
./console deploy                 # newest package, verify → extract → migrate → atomic switch → health
./console list && ./console status
./console rollback               # symlink only; --force when the database is ahead
```

## Container images

```bash
make build-images                # php-runtime + nginx-runtime, tagged :local
make test-image                  # builds them and checks compose.prod.yml serves /api/v1/health
```

Production images:

| Image | Stage | Contents |
|---|---|---|
| `analytics-php` | `php-runtime` | PHP-FPM 8.4 as `app` (uid 1000), `opcache.validate_timestamps=0`, release tree at `/app`, healthcheck through the FPM ping path |
| `analytics-cron` | `php-cron` | the same, plus supercronic running `deploy/docker/cron/crontab` |
| `analytics-web` | `nginx-runtime` | nginx 1.29 with `public/` at `/app/public` and the production site config |

`compose.prod.yml` runs `migrate` (one-shot) → `app` (healthy) → `web`, with the
profiles `scheduler`, `queue`, `bundled-db` and `redis`. Upgrade with
`docker compose pull && docker compose up -d`; `migrate` runs before the new
`app` accepts traffic.

## Cutting a release

1. Green `make ci` on `main`.
2. Update the changelog and tick the milestone in
   `docs/plan/implementation-plan.md`.
3. Tag and push:
   ```bash
   git tag -a v0.1.0 -m "analytics 0.1.0"
   git push origin v0.1.0
   ```
4. `.github/workflows/release.yml` then
   - builds the tarball, verifies the checksum and runs the deploy smoke test,
   - attaches `analytics-<TS>.tar.gz`, its `.sha256` and an SPDX SBOM to the
     GitHub release,
   - builds and pushes the three images for `linux/amd64` and `linux/arm64`
     with provenance and SBOM attestations, tagged with the version and `latest`.
5. Deploy: `./console deploy` on the tarball hosts, `docker compose pull && up -d`
   on the Docker hosts. Verify `/api/v1/health` reports the new commit.

Migrations are expected to be backward compatible for one release: a rollback
only moves the `current` symlink and never reverts the schema. The console warns
and requires `--force` when the database contains migrations the target release
does not know.
