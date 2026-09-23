# Development setup

Everything runs in containers. The only requirements on the host are **Docker**
(compose v2 + buildx) and **make**; PHP, Node and MySQL are never needed
locally, although Node 24 + corepack is convenient for running the dashboard dev
server outside the stack.

```bash
git clone git@github.com:manuto276/analytics.git
cd analytics
make help          # every target, with a one-line description
```

## 1. Host names and certificates

The stacks serve `https://analytics.test` with a local certificate authority.

```bash
make certs         # deploy/docker/certs/gen-certs.sh
sudo sh -c 'echo "127.0.0.1 analytics.test www.site.test app.site.test other.test proxy.site.test" >> /etc/hosts'
```

`gen-certs.sh` writes, into `deploy/docker/certs/` (git-ignored):

| File | Used by |
|---|---|
| `ca.pem` / `ca.key` | the local CA — trust it to browse without warnings |
| `analytics.test.pem` / `.key` | the service host (dev and test nginx) |
| `fixtures.pem` / `.key` | `site.test`, `*.site.test`, `other.test` (e2e fixtures) |

Trusting the CA is optional; the end-to-end suite does not depend on it (see
`services/e2e/playwright.config.ts`).

## 2. The development stack

```bash
make up            # mysql + php-fpm + nginx, project analytics-dev
make logs
make sh            # shell inside the php container
make down
```

| URL / port | What |
|---|---|
| `https://analytics.test:8443` | dashboard + API + `/t` (TLS) |
| `http://127.0.0.1:8080` | the same site over plain HTTP, handy for `curl` |
| `127.0.0.1:33062` | MySQL of the dev stack (33061 is a separate local database) |

Optional services come with profiles:

```bash
make up PROFILES=redis        # REDIS_DSN=redis://redis:6379/0
make up PROFILES=mail         # Mailpit UI on http://127.0.0.1:8025; php sends to it (MAILER_DSN=smtp://mailpit:1025)
make up PROFILES=node         # the Nuxt dev server inside the stack, port 3000
```

First run:

```bash
make install                                    # composer + pnpm dependencies
docker compose -p analytics-dev -f deploy/docker/compose.base.yml \
  -f deploy/docker/compose.dev.yml exec php php bin/analytics migrations:migrate
curl -k https://analytics.test:8443/api/v1/health
```

The dev container defines throw-away values for `APP_SECRET` and
`APP_ENCRYPTION_KEYS`; real values come from `.env` (template:
`deploy/docker/env.example`, generated with `bin/analytics secrets:generate`).

### Frontend during development

- **Dashboard**: `cd services/dashboard && pnpm dev` on the host. Nitro's
  `devProxy` sends `/api` and `/t` to the stack, so the browser stays
  same-origin. `pnpm generate:api` copies the built SPA into
  `services/api/public` and writes the CSP hashes to `services/api/config/csp.php`,
  which is what nginx and the backend serve.
- **Tracker**: `cd services/tracker && pnpm build:api` writes
  `services/api/resources/tracker/tracker.js` (core) and `banner.js` (consent
  banner module, added to `/t/{key}.js` only for sites with the cookie level on),
  the files the `/t/{key}.js` endpoint embeds. Without them the backend serves a
  harmless no-op stub (and no banner); `app:preflight` warns about it, and in
  production reports it as a failure.

## 3. The test stack

A second, isolated stack (project `analytics-test`) backs every `make test-*`
target: MySQL on tmpfs, the same PHP image with pcov, nginx as `analytics.test`,
the fixture sites, a Node container and the pinned Playwright container. It
publishes **no host ports**, so it never collides with the dev stack.

```bash
make test-unit
make test-functional
make e2e-setup && make test-e2e
make down                  # removes the test volumes too
```

See `docs/development/testing.md` for the full list.

## 4. Layout of the infrastructure

```
deploy/docker/
├── Dockerfile              all images: php-base, php-dev, node-build, vendor,
│                           release-tree, package, php-runtime, php-cron, nginx-runtime
├── compose.base.yml        mysql + php + nginx shared by dev and test
├── compose.dev.yml         ports, bind mounts, redis/mail/node profiles
├── compose.test.yml        tmpfs mysql, fixtures, console bridge, node, playwright
├── compose.prod.yml        migrate, app, web, scheduler, worker, mysql, redis
├── php/                    dev.ini, prod.ini, fpm-pool.conf
├── nginx/                  dev.conf, test.conf, prod.conf, fixtures.conf, snippets/
├── certs/gen-certs.sh      local CA and leaf certificates
├── cron/crontab            the schedule of plan §9 (supercronic)
├── env.example             template packaged as .env.example
└── scripts/                build-info.php, spa-csp.mjs, fpm-healthcheck.sh,
                            e2e-setup.sh, test-image.sh
```

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `bind: address already in use` on 8080/8443 | another stack is up: `make down` |
| Browser warns about the certificate | trust `deploy/docker/certs/ca.pem` (instructions printed by `make certs`) |
| `/` returns 404 in the dev stack | the SPA has not been generated yet: `cd services/dashboard && pnpm generate:api` |
| Files created by containers are not writable | the stacks run as your uid/gid (`DOCKER_UID`/`DOCKER_GID` exported by the Makefile); run commands through `make`, not plain `docker compose` |
| `ERR_PNPM_PACKAGE_MANAGER_CREATE_SLOT_DIR` or missing files under `node_modules` | the JavaScript containers keep their own Linux `node_modules` in named volumes (the host tree is a macOS install); `make node-init` hands those volumes to your user, and every JS target depends on it |
| `APP_SECRET is required` | `APP_ENV=prod` without secrets: use the dev/test stack or fill `.env` |
