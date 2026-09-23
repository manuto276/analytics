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
| `size` | size-limit, gzip: core ≤ 5.0 KB, banner ≤ 4.0 KB, both ≤ 9.0 KB | node container |
| `test-dashboard` | Vitest + @nuxt/test-utils | node container |
| `typecheck` `openapi-types` | `tsc`/`vue-tsc`, openapi-typescript | node container |
| `test-e2e` | Playwright, three engines | playwright container |
| `test-tracker-browser` | the `@tracker` subset of the e2e specs | playwright container |
| `test-deploy` | `deploy/manual/tests` PHPUnit | `php:8.4-cli` container |
| `test-smoke` | `deploy/manual/smoke/smoke.sh` with the newest package | managed-host containers |
| `test-image` | production images + `compose.prod.yml` health check | prod compose |
| `test-wordpress` | plugin unit tests + a real WordPress smoke (nightly in CI) | `php:8.4-cli` + WordPress compose |
| `lint` | PHP-CS-Fixer, ESLint, actionlint, hadolint, shellcheck | mixed |

The tracker, dashboard and Playwright containers keep their own Linux
`node_modules` in named volumes (the repository tree holds a host install that
is not portable into the containers); `make node-init` makes those volumes
writable and every JavaScript target depends on it. `make down` removes them.

The test database is `analytics_test` on the stack's MySQL
(`TEST_DATABASE_URL=mysql://root:root@mysql:3306/analytics_test`); ParaTest adds
one database per worker (`analytics_test_{TEST_TOKEN}`). MySQL runs on tmpfs
with `innodb_flush_log_at_trx_commit=0`: the data is disposable.

## End to end

```bash
make e2e-setup                       # stack + migrations + admin + site + fixtures/site-key.js
make test-e2e
make test-e2e E2E_ARGS="--project=chromium tests/consent.spec.ts"
make test-e2e E2E_ARGS="--grep @tracker"
make e2e-snapshots                   # regenerate the screenshot baselines
```

The suite covers the scenarios of plan §13.2: sign-in and TOTP enrolment,
invitations and role restrictions, site creation, base-level tracking through
the fixtures (including the forbidden origin and the first-party proxy),
`rollup:run` → Overview/Pages/Sources/realtime, the whole consent life cycle
(accept, shared `an_vid` across subdomains, reject, material change, reopen,
`/t/forget`), server-side conversions with attribution, `customer_ref`
follow-ups, funnels and the cost import that produces CAC/ROAS, axe audits in
both languages and two screenshot baselines. `services/e2e/README.md` lists
every spec and the helpers behind them.

Because the Playwright image has no docker client, operator commands
(`rollup:run`, `api-key:create`, `dev:seed`, …) reach the application through a
test-only **console bridge**: a small PHP server
(`services/e2e/support/console-bridge.php`) running in the `console` service of
the test stack, reachable only on the compose network and limited to an
allow-list of commands. It exists solely in `compose.test.yml` and is never part
of a release.

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
   browsers, so a test that expects tracking must call `hideWebdriver(page)`
   (`services/e2e/support/tracking.ts`) first.
2. The tracker batches events and flushes after ~1 s: assert on the `/t/e`
   response or count the collected batches, never on a fixed wait. Request
   bodies of `sendBeacon` are not readable in every engine, so do not filter
   `waitForResponse` by post data.

Reports and traces land in `services/e2e/playwright-report/` and
`services/e2e/test-results/` (uploaded by CI when a job fails).

## Load baseline

```bash
make perf                                  # seeds ~200k events, then 100 rps of /t/e for 60 s
make perf SEED_EVENTS=5000000 PERF_DAYS=60 DURATION=300s   # the plan's 5M-event baseline
make perf PERF_SKIP_SEED=1 COLLECT_RPS=200 # reuse the seeded data, push harder
```

`deploy/docker/scripts/perf.sh` prepares the stack and the `perf` site, seeds it
with `dev:seed` (visits per day derived from `SEED_EVENTS`, roughly four events
per visit) and runs `services/e2e/perf/collect.js` in the `grafana/k6` container
on the compose network. Two scenarios: collect batches ramping to `COLLECT_RPS`
(default 100) and the overview/pages/sources reports at `REPORTS_RPS` (default
5, with a real session cookie). The plan's budgets — collect p95 < 50 ms,
reports p95 < 500 ms — are k6 thresholds, so the summary shows whether they were
met; the nightly job runs with `continue-on-error`, since a shared CI runner is
not a performance reference. The raw metrics land in
`services/e2e/perf/summary.json` (git-ignored, uploaded as a nightly artifact).

Three details make the measurement describe the application rather than the
harness:

- **Production configuration.** After seeding, `perf.sh` recreates the php
  container with `deploy/docker/compose.perf.yml`: `APP_ENV=prod` (compiled
  PHP-DI container, warmed with `cache:warmup`), the production `php.ini`,
  Redis for the cache and rate limits, and `LOG_LEVEL=warning`. In the ordinary
  test configuration the same request takes an order of magnitude longer,
  because the container is rebuilt on every request and OPcache revalidates
  every file. `dev:seed` refuses `APP_ENV=prod`, which is why the seeding runs
  first.
- **Realistic headers.** Every batch carries a browser `User-Agent` *and* an
  `Accept-Language`: a request without the latter is treated as a bot, answered
  `202` and dropped, so the script would otherwise time the bot filter.
- **Spread client IPs.** The collect limiter allows 300 requests per minute per
  (site, shortened IP), so the script sends each batch from a different /24.

Traffic goes over plain HTTP to `http://analytics.test` inside the compose
network: TLS handshakes would measure the proxy rather than the application.

Measured on a MacBook (Docker Desktop, arm64, 60 s at ~100 rps over ~70k seeded
events): collect avg 21 ms / p95 32 ms, reports avg 14 ms / p95 19 ms, no failed
requests — both budgets met, with the caveats above.

## Deploy tooling

```bash
make package        # dist/analytics-<TS>.tar.gz + .sha256 (buildx, no PHP/Node on the host)
make test-deploy    # the console unit suite
make test-smoke     # init → deploy → health → deploy → rollback → list/status/cleanup
```

`test-smoke` generates fresh secrets (`APP_SECRET`, `APP_ENCRYPTION_KEYS`) and
passes them to the smoke stack with `--env-file`; everything else comes from the
`.env.example` inside the package.

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

- `perf` (k6) expects `services/e2e/perf/collect.js`, which does not exist yet; the target prints a
  message and skips.
- **Mutation testing is unavailable**: `infection/infection` cannot be installed next to PHPUnit 13
  (it requires `sebastian/diff` < 8, PHPUnit 13 pulls 8). `make mutation` and the nightly job skip
  with a warning until Infection supports PHPUnit 13.
- `test-tracker-browser` is `test-e2e` filtered by `--grep @tracker`, so it only runs what the e2e
  specs have tagged.
- The only axe allowance left in `a11y.spec.ts` is WebKit-specific: it reports the consent banner
  buttons as low contrast because it cannot read the styles adopted into the shadow root. The real
  values are enforced by the backend (it refuses a theme below 4.5:1) and audited by the same test in
  Chromium and Firefox.
- The screenshot baselines follow the design: they were last regenerated when the primary colour
  moved to green-700 and the report tabs became neutral pills.
- Screenshot baselines (`services/e2e/tests/visual.spec.ts-snapshots/`) are generated in the
  Playwright container on arm64 but are **not** arm64-only: the committed files also pass from the
  `linux/amd64` image (verified with `docker run --platform linux/amd64 … npx playwright test
  tests/visual.spec.ts`), because the comparison allows a 5% pixel ratio with a 0.3 per-pixel
  threshold. Regenerate them with `make e2e-snapshots` after an intended design change.
- Beacons sent while a page is going away are best-effort, so tracking assertions use a floor
  ("at least N batches") rather than exact counts.

## Writing a test

| Kind | Where | Base class / helper |
|---|---|---|
| Pure logic | `services/api/tests/Unit` | plain `PHPUnit\Framework\TestCase` |
| Database behaviour | `services/api/tests/Integration` | `Analytics\Tests\Support\IntegrationTestCase` |
| HTTP behaviour | `services/api/tests/Functional` | `Analytics\Tests\Support\HttpTestCase` |
| Schema | `services/api/tests/Migrations` | `MigrationsTest` |
| Tracker | `services/tracker/test/*.test.ts` | helpers in `test/helpers.ts` |
| Browser | `services/e2e/tests/*.spec.ts` | Playwright |
| Deploy console | `deploy/manual/tests` | `DeployTestCase` |

Support classes worth knowing:

- `Analytics\Tests\Support\Factory` — builders for sites, users, invitations, API keys, consent
  configurations;
- `Analytics\Tests\Support\Payloads` — tracking payload v1 builders (`pageview`, `event`,
  `engagement`, `consentStat`, `consentUpgrade`, `batch`);
- `Analytics\Tests\Support\Scenario\SeededDataset` — a deterministic multi-channel dataset shared
  by the integration and reporting tests;
- `HttpTestCase` — `request()`, `get()`, `collect()`, `assertStatus()`, `assertProblem()`, a frozen
  clock, and OpenAPI validation of every request and response (disable per test with
  `$this->validateOpenApi = false`).

The clock is frozen in the test environment (`APP_TEST_CLOCK`, honoured only when `APP_ENV=test`), so
tests can assert on exact days and on time-based behaviour such as the 30-minute visit timeout and the
13-month retention cutoff.
