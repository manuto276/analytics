# Contributing

Thanks for looking. This is a self-hosted, privacy-first web analytics service licensed
**AGPL-3.0-or-later**; the optional WordPress plugin under
`services/wordpress-plugin/analytics-connector` is GPL-2.0-or-later.

Participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).
Security problems are **not** reported as issues — follow [SECURITY.md](SECURITY.md).

## Before you start

- Open an issue first for anything that changes behaviour, adds an endpoint, adds a dependency or
  touches the data model. A small fix or a documentation correction can go straight to a pull request.
- Read [development/conventions.md](development/conventions.md) and, for the area you are touching,
  the matching page under [architecture/](architecture/).
- If you are adding a runtime dependency, say why in the issue. The tracker takes **none**.

## Setting up

Everything runs in containers; you need Docker (compose v2 + buildx) and `make`.

```sh
git clone git@github.com:manuto276/analytics.git
cd analytics
make help
make certs && make up
make install
```

Full instructions: [development/setup.md](development/setup.md).

## The loop

```sh
make test-unit                # fast
make test-functional          # HTTP + database
make lint stan deptrac        # style, static analysis, layering
make ci                       # exactly what CI runs, in the same order
```

`make ci` is the gate. It is slow; run the narrow targets while iterating.

## Pull requests

1. Branch from `main`.
2. One logical change per commit; subject in the imperative or as a noun phrase, ≤ 72 characters,
   body explaining *why*. See [development/conventions.md](development/conventions.md#commits).
3. Include tests. Where they belong is in
   [development/testing.md](development/testing.md#writing-a-test).
4. Update the documentation in the same commit — especially:
   - a new error code → [api/errors.md](api/errors.md);
   - a new or changed endpoint → `docs/api/openapi.yaml` (a functional test validates every request
     and response against it, and a route absent from it fails the build);
   - a change to what is stored → [privacy/data-inventory.md](privacy/data-inventory.md);
   - a change to cookies or consent → [privacy/cookies.md](privacy/cookies.md) **and**
     [privacy/garante-2021-mapping.md](privacy/garante-2021-mapping.md);
   - a decision with consequences → a new ADR from
     [architecture/adr/0000-template.md](architecture/adr/0000-template.md).
5. Run `make ci` locally before asking for a review.
6. Describe what changed and why, and how you verified it.

Contributions are accepted under the repository's licence. Sign your commits off (`git commit -s`,
the Developer Certificate of Origin) so the licensing stays clear — see
[architecture/adr/0006-tracker-license.md](architecture/adr/0006-tracker-license.md) for why that
matters here.

## Things that will be refused

- **Anything that weakens a privacy invariant.** The five invariants in
  [architecture/overview.md](architecture/overview.md#the-five-invariants) each have a test. Storing a
  full IP address or User-Agent, setting a cookie on the service domain, joining base-level rows to a
  visitor id, or adding an endpoint that returns individual rows are all out of scope, whatever the
  benefit.
- **Client-specific code.** The product is generic. Examples use `example.com` / `example.net`; test
  hosts are `analytics.test` and `*.site.test`.
- **A runtime dependency in the tracker**, or a change that pushes it over its gzip budgets
  (core 5.0 KB, banner 4.0 KB, together 9.0 KB; `make size`).
- **A PHPStan baseline.** Level max, no baseline. Fix the finding or explain it in the code.
- **A destructive migration in a single release.** Expand/contract only; the lint in
  `MigrationsTest::testMigrationsAreForwardOnlyAndExpandOnly` enforces it. See
  [deploy/upgrading.md](deploy/upgrading.md).
- **A route without a declared permission or scope.** `SecuredRoutes` requires one and
  `RbacMatrixTest` fails otherwise.
- Generated artefacts or real secrets in a commit.

## Areas that need care

| Area | Why |
|---|---|
| `Tracking` | the hot path and the privacy boundary; every change needs a test and a look at [architecture/ingestion.md](architecture/ingestion.md) |
| `Reporting/Rollup` | rollup and raw must stay equal by construction — add to `RawSelects`, not beside it |
| Migrations | forward-only, expand/contract, raw SQL for the DBAL tables |
| `deploy/manual/console` | no Composer dependencies, ever: it must run when the current release cannot |
| `services/tracker` | size budget, no dependencies, and "nothing stored before a choice" |
| Consent | published revisions are immutable; changing the version re-asks every visitor |

## Reporting a bug

Include: what you did, what you expected, what happened, the version and commit (from
`GET /api/v1/health` or the dashboard footer), the deployment mode (tarball or Docker), PHP and MySQL
versions, and the relevant log lines — with the `request_id` if you have an error response.

Never paste a real `.env`, an API key, a session cookie or a database dump into an issue.

## Suggesting a feature

Say what problem it solves and for whom. Features that need a persistent identifier belong to the
cookie level by definition; features that would make the base level more identifying are unlikely to
be accepted. If it changes what is collected, expect the discussion to include
[privacy/legal-review-points.md](privacy/legal-review-points.md).

## Translations

Dashboard strings live in `services/dashboard/i18n/locales/`. Every locale must carry every key —
`pnpm i18n:check` enforces it. See [development/i18n.md](development/i18n.md).

## Releases

Maintainers cut releases; the procedure is [development/release.md](development/release.md).
