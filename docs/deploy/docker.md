# Docker deployment

`deploy/docker/compose.prod.yml` is a self-contained production stack. Everything is built from one
`deploy/docker/Dockerfile`, so the images and the [tarball](tarball.md) contain the same application
tree.

## Images

| Image | Stage | Contents |
|---|---|---|
| `ghcr.io/manuto276/analytics-php` | `php-runtime` | PHP-FPM 8.4 as the `app` user (uid 1000), the release tree at `/app`, `opcache.validate_timestamps=0`, an FPM ping healthcheck |
| `ghcr.io/manuto276/analytics-cron` | `php-cron` | the same, plus supercronic running `deploy/docker/cron/crontab` |
| `ghcr.io/manuto276/analytics-web` | `nginx-runtime` | nginx 1.29-alpine with `public/` at `/app/public` and `deploy/docker/nginx/prod.conf` |

`/app` is the same absolute path in the PHP and the nginx image, so `$realpath_root` resolves
identically on both sides.

Tags: the build version (the UTC timestamp) and `latest`, built for `linux/amd64` and `linux/arm64`
with provenance and SBOM attestations by `.github/workflows/release.yml`.

## Compose files

| File | Use |
|---|---|
| `compose.base.yml` | mysql + php + nginx shared by the dev and test stacks; never used alone |
| `compose.dev.yml` | development overlay (ports, bind mounts, `redis`/`mail`/`node` profiles) |
| `compose.test.yml` | test overlay (tmpfs MySQL, fixture sites, node, Playwright) |
| `compose.prod.yml` | production; self-contained |

Development and testing are covered in [../development/setup.md](../development/setup.md).

## Services in `compose.prod.yml`

| Service | Profile | What it does |
|---|---|---|
| `migrate` | — | one-shot `migrations:migrate --no-interaction --allow-no-migration`; `restart: "no"` |
| `app` | — | PHP-FPM; starts only after `migrate` completes successfully; healthcheck `fpm-healthcheck` |
| `web` | — | nginx; starts only when `app` is healthy; publishes `${ANALYTICS_HTTP_BIND:-127.0.0.1:8080}:80` |
| `scheduler` | `scheduler` | supercronic running the cron schedule |
| `worker` | `queue` | `queue:work --max-time=3600`; needs `INGEST_MODE=queue` and `REDIS_DSN` |
| `mysql` | `bundled-db` | MySQL 8.4 with the `mysql-data` volume |
| `redis` | `redis` | Redis 7 alpine, no persistence, `allkeys-lru` |

Volumes: `mysql-data`, `storage` (`/app/var/storage` — the geo database, locks, backups), `logs`
(`/app/var/log`).

TLS is expected to terminate in front of `web` (a reverse proxy or ingress). Set `TRUSTED_PROXIES` so
the forwarded client address is the one that gets shortened.

## Setting it up

```sh
cp deploy/docker/env.example deploy/docker/.env
$EDITOR deploy/docker/.env          # APP_URL, APP_SECRET, APP_ENCRYPTION_KEYS, DATABASE_URL
docker compose -f deploy/docker/compose.prod.yml up -d
```

`env_file` paths are relative to the compose file; override with `ANALYTICS_ENV_FILE`.

Generate the secrets:

```sh
docker compose -f deploy/docker/compose.prod.yml run --rm app php bin/analytics secrets:generate
```

### With the bundled database

```sh
docker compose -f deploy/docker/compose.prod.yml --profile bundled-db up -d
```

`MYSQL_ROOT_PASSWORD` and `MYSQL_PASSWORD` become required; `MYSQL_DATABASE` and `MYSQL_USER` default
to `analytics`. Point `DATABASE_URL` at `mysql://analytics:…@mysql:3306/analytics?serverVersion=8.4`.

An external managed database is the other option: leave the profile off and set `DATABASE_URL`.

### Scheduled jobs

```sh
docker compose -f deploy/docker/compose.prod.yml --profile scheduler up -d
```

Without this profile **nothing is rolled up, no salt is rotated, no partition is created and retention
never runs**. Either enable it or run the same commands from the host's cron
([../operations/cron.md](../operations/cron.md)).

### Redis and queue mode

```sh
docker compose -f deploy/docker/compose.prod.yml --profile redis up -d
# in .env:  REDIS_DSN=redis://redis:6379/0
# for queue mode, additionally:  INGEST_MODE=queue
docker compose -f deploy/docker/compose.prod.yml --profile redis --profile queue up -d
```

Redis alone (without queue mode) still moves the cache, the rate limits, the locks and the daily salt
off MySQL. The `worker` service replaces the cron `queue:work` line; the packaged crontab has that
line commented out for this reason.

## First administrator and first site

```sh
C="docker compose -f deploy/docker/compose.prod.yml"
$C exec app php bin/analytics user:create-admin --email=admin@example.com
$C exec app php bin/analytics site:create --name=Example --domain='*.example.com' --timezone=Europe/Rome
$C exec app php bin/analytics geo:update
```

## Upgrading

```sh
docker compose -f deploy/docker/compose.prod.yml pull
docker compose -f deploy/docker/compose.prod.yml up -d
```

`migrate` runs to completion before the new `app` accepts traffic, and `web` waits for `app` to be
healthy. Pin a version instead of `latest` for reproducibility:

```dotenv
ANALYTICS_VERSION=20260917T190000Z
```

Rolling back means setting `ANALYTICS_VERSION` to the previous tag and running `up -d` again. The
schema is **not** reverted, so the same expand/contract rule as the tarball applies — see
[upgrading.md](upgrading.md).

## Operating

```sh
C="docker compose -f deploy/docker/compose.prod.yml"
$C ps
$C logs -f app web
$C exec app php bin/analytics health:check --json
$C exec app php bin/analytics jobs:status
$C exec app php bin/analytics migrations:status
curl -s http://127.0.0.1:8080/api/v1/health
```

Backups: dump the database from the `mysql` service (or from your managed database) **excluding
`daily_salts`**, and back up the `storage` volume. See
[../operations/backups.md](../operations/backups.md).

## Building the images locally

```sh
make build-images        # php-runtime and nginx-runtime, tagged :local
make test-image          # builds them and checks compose.prod.yml serves /api/v1/health
```

`deploy/docker/scripts/test-image.sh` is what CI runs.

## Reverse proxy in front

Terminate TLS outside and forward to the published port. Forward the client address and list the
proxy in `TRUSTED_PROXIES`:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

```dotenv
APP_URL=https://stats.example.net
TRUSTED_PROXIES=172.16.0.0/12,127.0.0.1
```

Do not cache API responses in the proxy: they are `private`. See
[../integration/caching-proxies.md](../integration/caching-proxies.md).

## Differences from the tarball deployment

| | Tarball | Docker |
|---|---|---|
| Release switch | `current` symlink, atomic, instant rollback | image tag, container restart |
| Migrations | `./console deploy` step 8 | the `migrate` service |
| Scheduler | host cron through `current` | `scheduler` profile (supercronic) |
| SPA fallback | nginx `try_files` (no document CSP) or PHP | PHP, so the document carries the CSP |
| Rollback | symlink move, works with a broken release | previous image tag |
| Where `.env` lives | `shared/.env` (0600), symlinked into each release | compose `env_file` |

## Related

[tarball.md](tarball.md) · [nginx.md](nginx.md) · [upgrading.md](upgrading.md) ·
[../development/release.md](../development/release.md) · [../operations/runbook.md](../operations/runbook.md)
