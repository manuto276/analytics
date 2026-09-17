# Testing

Every suite runs in the test stack (`deploy/docker/compose.base.yml` +
`compose.test.yml`, compose project `analytics-test`), so a local run and a CI
run execute the same commands in the same images.

```bash
make test           # unit + integration + functional + migrations + tracker + dashboard
make ci             # exactly what .github/workflows/ci.yml runs, in the same order
```

## Targets

| Target | What it runs | Where |
|---|---|---|
| `test-unit` | `vendor/bin/phpunit --testsuite unit` | php container (no database) |
| `test-integration` | `--testsuite integration` | php container + MySQL |
| `test-functional` | `--testsuite functional` | php container + MySQL |
| `test-migrations` | `--testsuite migrations` | php container + MySQL |
| `coverage` | PHPUnit with pcov, clover report | php container |
| `mutation` | Infection, MSI ≥ 80 (nightly) | php container |
| `stan` `deptrac` `cs` `cs-fix` `rector` | PHPStan (src + tests), Deptrac, PHP-CS-Fixer, Rector | php container |
| `test-tracker` | Vitest + happy-dom | node container |
| `size` | size-limit, ≤ 5.0 KB gzip | node container |
| `test-dashboard` | Vitest + @nuxt/test-utils | node container |
| `typecheck` `openapi-types` | `tsc`/`vue-tsc`, openapi-typescript | node container |
| `test-e2e` | Playwright, three engines | playwright container |
| `test-tracker-browser` | the `@tracker` subset of the e2e specs | playwright container |
| `test-deploy` | `deploy/manual/tests` PHPUnit | `php:8.4-cli` container |
| `test-smoke` | `deploy/manual/smoke/smoke.sh` with the newest package | managed-host containers |
| `test-image` | production images + `compose.prod.yml` health check | prod compose |
| `lint` | PHP-CS-Fixer, ESLint, actionlint, hadolint, shellcheck | mixed |

The test database is `analytics_test` on the stack's MySQL
(`TEST_DATABASE_URL=mysql://root:root@mysql:3306/analytics_test`); ParaTest adds
one database per worker (`analytics_test_{TEST_TOKEN}`). MySQL runs on tmpfs
with `innodb_flush_log_at_trx_commit=0`: the data is disposable.

## End to end

```bash
make e2e-setup                       # stack + migrations + admin + site + fixtures/site-key.js
make test-e2e
make test-e2e E2E_ARGS="--project=chromium tests/smoke.spec.ts"
```

`e2e-setup` (`deploy/docker/scripts/e2e-setup.sh`) is idempotent. It creates the
first admin with `bin/analytics user:create-admin` and one site with the domain
`*.site.test`, then writes the public key to `services/e2e/fixtures/site-key.js`
so no fixture page contains a hard-coded key. `other.test` is deliberately left
unregistered, so the collect endpoint must refuse it.

The fixture hosts are compose network aliases, which is why the Playwright
container can browse `https://www.site.test` directly. Browser contexts use
`ignoreHTTPSErrors` (installing a CA into three browser engines inside the image
is brittle); Node itself trusts the CA through `NODE_EXTRA_CA_CERTS`.

Two things trip up every new e2e test:

1. Playwright sets `navigator.webdriver` and the tracker skips automated
   browsers, so a test that expects tracking must override it first
   (`hideWebdriver` in `services/e2e/tests/smoke.spec.ts`).
2. The tracker batches events and flushes after ~1 s: assert on the `/t/e`
   response, never on a fixed wait.

Reports and traces land in `services/e2e/playwright-report/` and
`services/e2e/test-results/` (uploaded by CI when a job fails).

## Deploy tooling

```bash
make package        # dist/analytics-<TS>.tar.gz + .sha256 (buildx, no PHP/Node on the host)
make test-deploy    # the console unit suite
make test-smoke     # init → deploy → health → deploy → rollback → list/status/cleanup
```

`test-smoke` generates fresh secrets and passes them to the smoke stack with
`--env-file`, including an `https://` `APP_URL`: the smoke container serves plain
HTTP, and `bin/analytics app:preflight` fails a production install whose
`APP_URL` is not HTTPS.

## Continuous integration

`.github/workflows/ci.yml` (pull requests and pushes to `main`):

| Job | Contents |
|---|---|
| `php-quality` | composer validate/audit, PHPStan (src + tests), Deptrac, CS-Fixer, Rector |
| `lint-infra` | actionlint, hadolint, shellcheck, `docker compose config` for all three stacks |
| `php-tests` | matrix 8.4 / 8.5 against a MySQL 8.4 service; all four suites; coverage on 8.4 |
| `tracker` | lint, typecheck, test, build, size budget |
| `dashboard` | lint, typecheck, OpenAPI type freshness, test, generate |
| `deploy-console` | `deploy/manual/tests` + `php -l` on the console |
| `e2e` | needs the three above: full stack, Playwright, report on failure |
| `package` | tarball, checksum, manifest and layout assertions, deploy smoke test |
| `image` | production images and `compose.prod.yml` health check |

`nightly.yml` adds mutation testing, the full browser matrix, the upgrade
migration test, dependency audits, the WordPress plugin smoke and a ZAP
baseline. `codeql.yml` analyses JavaScript/TypeScript and the workflows
themselves (CodeQL has no PHP support; PHPStan max and Deptrac cover the
backend).

## Known gaps

- `tests/Migrations` is still empty, so `make test-migrations` fails with
  "No tests executed" until the migration suite lands (plan M1).
- `mutation` needs `infection/infection` in the API's dev dependencies; the
  target and the nightly job skip with a warning while it is missing.
- `perf` (k6) expects `services/e2e/perf/collect.js`, which arrives with M12.
