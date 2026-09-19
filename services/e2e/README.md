# End-to-end suite

Playwright against the full compose test stack: the real backend, the real
tracker bundle, the generated dashboard and four static fixture sites, all over
HTTPS with a local CA.

```
make e2e-setup     # stack up, database migrated, admin + site created
make test-e2e      # the whole suite in the pinned Playwright container
make test-e2e E2E_ARGS="--project=chromium tests/consent.spec.ts"
make test-e2e E2E_ARGS="--grep @tracker"
make e2e-snapshots # regenerate the screenshot baselines
```

`make down` removes the stack (including its database volume).

## Layout

```
playwright.config.ts   projects chromium / firefox / webkit, baseURL https://analytics.test
global-setup.ts        resolves the site public key, writes fixtures/site-key.js, waits for health
support/               helpers shared by the specs (see below)
tests/                 one spec file per scenario group
tests/visual.spec.ts-snapshots/   committed screenshot baselines (chromium)
fixtures/              the tracked sites served by the `fixtures` nginx container
```

| Fixture host | Purpose |
|---|---|
| `www.site.test` | multi-page site: home, pricing, declarative events, strict-CSP page, consent footer link |
| `app.site.test` | small SPA (`history.pushState`), same cookie domain `.site.test` |
| `other.test` | origin that is **not** registered for the site: collect must refuse it |
| `proxy.site.test` | first-party proxy: tracker loaded from `/stats/<key>.js`, events to `/stats/e` |

Each fixture page loads `/site-key.js` (written per run, never committed) and
accepts `?an_key=pk_…` so a single test can point the fixtures at the site it
just created; `?an_cb=…` busts the browser cache of the tracker bundle, which
matters after publishing a new consent version.

## Scenarios

| Spec | Covers |
|---|---|
| `smoke.spec.ts` | health endpoint, one collected visit, no `Set-Cookie` on `/t/*`, tracker bundle |
| `auth.spec.ts` | console-created admin signs in, TOTP enrolment, sign-in with a code, wrong code, recovery code, redirect to `/login` |
| `members.spec.ts` | invite a viewer, accept the link, viewer reads reports but cannot manage (UI and API) |
| `sites.spec.ts` | create a site with domains from the dashboard, snippet and public key, add a second domain; domains committed without Enter (blur, comma, pending text on Create), `*.` for subdomains, invalid host flagged before sending |
| `profile.spec.ts` | display name and language saved from the profile page persist across a reload and on the account (the email change needs a mailer; the test stack has none) |
| `tracking.spec.ts` | base-level visits on the fixtures, forbidden origin, first-party proxy, `rollup:run` → Overview/Pages/Sources, realtime |
| `consent.spec.ts` | banner before any storage, accept → `an_vid` shared across subdomains, reject → remembered, material change → asked again, reopen link, `/t/forget`, consent report |
| `conversions.spec.ts` | goals, server-side conversions (idempotency, `customer_ref` follow-up), attribution by campaign, funnel counts, cost CSV import → CAC/ROAS |
| `a11y.spec.ts` | axe on the banner, the dashboard in English and Italian, a report table, the sign-in page |
| `visual.spec.ts` | screenshot baselines of the sign-in page and the Overview KPI cards (Chromium) |

## Load baseline (k6)

```bash
make perf                                  # ~200k seeded events, 100 rps for 60 s
make perf SEED_EVENTS=5000000 PERF_DAYS=60 DURATION=300s   # the plan's baseline
make perf PERF_SKIP_SEED=1                 # reuse the data already seeded
```

`deploy/docker/scripts/perf.sh` brings the stack up, creates the `perf` site,
seeds it with `bin/analytics dev:seed` (the visits-per-day option is derived
from `SEED_EVENTS`, about four events per visit) and then runs
`perf/collect.js` in the `grafana/k6` container on the compose network.

The script drives two scenarios — `POST /t/e` batches (mixed pageviews,
engagement and custom events, a quarter of them consented, varied user agents,
languages, paths, referrers and campaigns, each from a different /24 so the
rate limiter is not what gets measured) ramping to `COLLECT_RPS`, and the
overview, pages and sources reports at `REPORTS_RPS` with a real session cookie.
The measurement phase runs the application in its production configuration
(`deploy/docker/compose.perf.yml`: `APP_ENV=prod`, compiled container,
production `php.ini`, Redis cache), because the test configuration rebuilds the
DI container on every request.

The plan's budgets (collect p95 < 50 ms, reports p95 < 500 ms) are k6
thresholds, so they appear in the summary; the nightly job runs the whole thing
with `continue-on-error`, because a shared runner is not a performance
reference. Local reference numbers (MacBook, Docker Desktop, ~100 rps for 60 s):
collect p95 32 ms, reports p95 19 ms.

## Support helpers

| File | What it does |
|---|---|
| `support/console.ts` | runs allow-listed `bin/analytics` commands through the console bridge |
| `support/console-bridge.php` | the bridge itself: a tiny PHP server in the `console` container of the test stack (it exists because the Playwright image has no docker client) |
| `support/api.ts` | logs into the JSON API (Origin + `X-CSRF-Token`), site/consent/goal/funnel helpers, server-side conversions |
| `support/tracking.ts` | fixture visits, collect batches, cookies, banner locators, `createSite`, `createApiKey`, `rollup` |
| `support/dashboard.ts` | login, deterministic page setup (light mode, preselected site), report URLs, KPI readers |
| `support/totp.ts` | RFC 6238 codes, skipping steps already used (the backend is replay-protected) |

## Conventions

- Playwright sets `navigator.webdriver` and the tracker skips automated
  browsers, so every test that expects tracking calls `hideWebdriver(page)`.
- The tracker batches events and flushes after ~1 s; assert on the `/t/e`
  response, or count the collected batches — do not sleep and hope.
- Request bodies of `sendBeacon` are not readable in every engine, so never
  filter `waitForResponse` by post data.
- The first admin is created with `bin/analytics user:create-admin`, never over
  HTTP: that is what an operator does on a fresh install.
- Each spec file creates its own site (and its own users), so a project can run
  alone and the counts stay exact.
- Tag browser-only tracker scenarios with `@tracker` (`make
  test-tracker-browser` selects them) and screenshot tests with `@visual`.

## Known gaps and quirks

- **Server-side conversions from the fixture app**: `app.site.test` submits a
  form that a real customer backend would turn into
  `POST /api/v1/server/.../conversions`. The suite posts that request directly
  with an API key instead of running a fake customer backend in the stack.
- **Certificate trust**: browser contexts use `ignoreHTTPSErrors` (see the
  comment in `playwright.config.ts`); certificate handling itself is covered by
  the deploy smoke test.
- **Beacons during navigation** are best-effort: WebKit occasionally drops the
  last batch of a page that is going away, so count assertions have a floor
  rather than an exact value.
- **Known dashboard issues** are allow-listed in `a11y.spec.ts` (muted
  `[data-slot="label"]` text below 4.5:1) and commented in `auth.spec.ts` (a
  wrong MFA code returns to the password form) — both reported to the dashboard
  owner; remove the allowances when they are fixed.
- **Screenshot baselines** are generated in the Playwright container. They were
  produced on arm64; if CI (amd64) reports small antialiasing differences,
  regenerate them there with `make e2e-snapshots` and commit the result.
