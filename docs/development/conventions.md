# Conventions

Everything below is enforced by a tool where it can be. `make lint`, `make stan`, `make deptrac` and
`make rector` are what CI runs; run them before opening a pull request.

## Language

Code, comments, commit messages, documentation and identifiers are **English**. The only translated
strings are the dashboard's `i18n/locales/{en,it}.json` and the consent banner texts, which are data
— see [i18n.md](i18n.md).

## Files and whitespace

`.editorconfig` at the repository root:

| Scope | Rule |
|---|---|
| everything | UTF-8, LF, final newline, no trailing whitespace, 2-space indent |
| `*.php` | 4-space indent |
| `Makefile` | tabs |
| `*.md` | trailing whitespace kept (line breaks) |

## PHP

**Style**: PER-CS 2.0 through PHP-CS-Fixer (`services/api/.php-cs-fixer.dist.php`), with
`declare(strict_types=1)` in every file, no unused imports, imports ordered class → function → const
alphabetically, single quotes, trailing commas in multi-line arrays, arguments, parameters and
`match`, and namespaced native function/constant invocation.

```sh
make cs        # dry run with a diff
make cs-fix    # write
```

**Static analysis**: PHPStan at `level: max` with the doctrine, phpunit, strict-rules and
deprecation-rules extensions, over `src`, `bin/analytics`, `public/index.php` and `migrations`, plus a
second pass over the tests (`phpstan-tests.neon.dist`). **No baseline.** Strict rules on:
loose comparisons, booleans in conditions, useless casts, strict function calls and the rest listed in
`phpstan.neon.dist`.

**Rector**: `withPhpSets(php84)` plus the dead-code, code-quality, type-declarations and early-return
sets. `make rector` is a dry run; apply with `vendor/bin/rector process`.

**Language level**: PHP 8.4. Use what it offers — property hooks where they clarify, `readonly`
classes for value objects and services, enums for closed sets, constructor promotion, first-class
callable syntax, `never`/`true`/`false` return types. Constants that are lists are typed
(`public const array SCOPES = [...]`).

**Shape of a class**:

- services are `final readonly` with constructor-promoted dependencies;
- value objects are `final readonly`, immutable, with named constructors (`fromSite`, `fromPacked`);
- entities are plain classes with Doctrine attributes and public properties;
- everything is `final` unless there is a reason not to be.

**Types**: annotate arrays precisely (`list<Filter>`, `array<string, mixed>`,
`array{sql: string, params: array<string, string>}`). `Analytics\Shared\Types` holds the helpers that
turn a DBAL `mixed` into an `int`/`string` without `@phpstan-ignore`.

**Errors**: throw `ApiProblem` for anything the client should see; it carries the status, the stable
`code`, an optional detail, field errors and headers. Every new code must be documented in
[../api/errors.md](../api/errors.md) with a matching anchor — the `type` URL of the response points
there. Use `LogicException` for programming errors (a route without a permission, an unpersisted
entity).

**Validation**: use `Analytics\Shared\Validation\Input`. It collects field errors and
`assertValid()` throws one `422` with all of them, rather than failing on the first.

**SQL**: parameterised, always. Never interpolate user input. Report SQL lives in `RawSelects`,
`TableReports` and the report classes; the same fragments feed the rollup builder, which is what keeps
the two paths equal ([../architecture/reporting.md](../architecture/reporting.md)).

**Privacy rules that are not negotiable**: never log or store a full IP address or User-Agent; never
join base-level rows to a `visitor_id`; never add an endpoint that returns an individual visitor,
event or conversion row. Each has a test that will fail
([../architecture/overview.md](../architecture/overview.md#the-five-invariants)).

## Module layering

Modules live under `services/api/src/<Module>/` with `Domain/`, `Application/`, `Infrastructure/`,
`Http/` and `Console/`. Deptrac enforces the dependency direction
([../architecture/modules.md](../architecture/modules.md#layering)):

```
Domain → Shared
Application → Domain, Shared, Kernel
Infrastructure → Application, Domain, Shared, Kernel
Http → Application, Domain, Shared, Kernel        (never Infrastructure)
Console → Application, Domain, Shared, Kernel, Infrastructure
```

A new module is a class extending `Analytics\Kernel\Module`, registered in
`Analytics\Kernel\Modules::all()`. Routes go through `SecuredRoutes` and **must** declare a permission
(session API) or a scope (server API); `AccessMiddleware` and `ApiKeyAuthMiddleware` throw if they do
not, and `RbacMatrixTest` fails the build.

## Naming

| Thing | Convention | Example |
|---|---|---|
| Namespace | `Analytics\<Module>\<Layer>` | `Analytics\Tracking\Application\Enrichment` |
| Class | `PascalCase`, named after what it does | `VisitorHasher`, `ChannelClassifier` |
| Interface | no `I` prefix, no `Interface` suffix when the name reads well | `EventSink`, `DailySaltProvider`, `GeoLocator` |
| Implementation | prefixed by its technology | `RedisQueueEventSink`, `DbalDailySaltProvider`, `MaxMindGeoLocator` |
| Method | `camelCase`, verb first | `resolveVisit()`, `isOwnHost()` |
| Constant | `UPPER_SNAKE_CASE` | `VISIT_TIMEOUT_SECONDS`, `MAX_EVENTS` |
| Database table | `snake_case`, plural; rollups `rollup_<subject>_<grain>` | `events_raw`, `rollup_pages_daily` |
| Column | `snake_case`; timestamps `*_at`, local dates `local_day`/`day`, flags `is_*`/`has_*` | `occurred_at`, `is_bounce` |
| Index | `idx_<table>_<columns>`, unique `uniq_<table>_<columns>` | `idx_events_site_day_type` |
| Console command | `group:verb` | `rollup:run`, `api-key:create` |
| Route | plural nouns, `{siteId}`/`{publicKey}` parameters with a regex constraint | `/sites/{siteId:[0-9]+}/reports/pages` |
| JSON field | `snake_case` | `revenue_minor`, `next_cursor` |
| Payload field | one or two characters, documented in the schema | `v`, `k`, `l`, `e`, `pv` |
| Money | integer minor units plus an ISO 4217 code | `amount_minor`, `currency` |
| Test | `Test` suffix, methods `testWhatItAsserts` | `testBasePageviewIsStoredAnonymously` |

## TypeScript

**Tracker** (`services/tracker`): no runtime dependencies, ES2019 output, and a hard size budget of
5.0 KB gzipped for core plus banner (`size-limit`, `make size`). That budget shapes the style —
short identifiers, no classes, no polyfills, `==` where the coercion is intended. Do not import a
library into it. ESLint + `tsc --noEmit` (`make lint`, `make typecheck`).

**Dashboard** (`services/dashboard`): Nuxt 4 + Nuxt UI conventions, `@nuxt/eslint` with the stylistic
rules configured in `eslint.config.mjs` (no trailing commas, 1TBS braces), `vue-tsc` for types. API
types are **generated** from `docs/api/openapi.yaml` (`pnpm openapi-types`) into `app/types/api.d.ts`,
which is git-tracked and checked for freshness in CI — never edit it by hand.

## Shell, Docker, workflows

`make lint` runs shellcheck over the scripts in `deploy/`, hadolint over the Dockerfile
(`--failure-threshold warning`) and actionlint over the workflows. Shell scripts are `bash` with
`set -euo pipefail` and a comment header that doubles as the `--help` output.

The deploy console (`deploy/manual/console`) is deliberately dependency-free PHP: no Composer, no
autoloader, no extension in its filename. Keep it that way — it has to work when the current release
does not.

## Documentation

`docs/` is the source of truth for behaviour. A change in behaviour updates the page that describes
it in the same commit. In particular:

- a new error code → [../api/errors.md](../api/errors.md);
- a new endpoint → `docs/api/openapi.yaml` (a functional test validates every request and response
  against it, and `RbacMatrixTest::testEverySessionRouteIsDocumentedInOpenApi` fails otherwise);
- a change to what is stored → [../privacy/data-inventory.md](../privacy/data-inventory.md);
- a change to cookies or consent → [../privacy/cookies.md](../privacy/cookies.md) **and**
  [../privacy/garante-2021-mapping.md](../privacy/garante-2021-mapping.md) (this is a release gate);
- a decision with consequences → a new ADR from
  [../architecture/adr/0000-template.md](../architecture/adr/0000-template.md).

Style: statements of fact in the present tense, tables where they help, no marketing tone, no emoji,
100–400 lines per page. Say "not implemented" plainly rather than describing an intention.

## Commits

One logical change per commit. Subject line in the imperative or as a noun phrase, capitalised, no
trailing full stop, ≤ 72 characters. Existing history uses a short scope followed by a summary:

```
Ingestion: payload v1 parser, enrichment, daily salt, visits, consent API, tracker script
Rollups and reports: additive daily rollups, query planner, report API, seeder
Tests: funnels, attribution, queue mode, query plans, admin endpoints, security headers
```

The body explains *why*, wraps at 72 columns, and references an issue where one exists. Do not commit
generated artefacts (`vendor/`, `node_modules/`, `dist/`, `.output/`, coverage) or any real secret.

## Tests

Every behavioural change comes with a test. Where it belongs:

| Kind | Where |
|---|---|
| Pure logic | `tests/Unit` |
| Database behaviour | `tests/Integration` (real MySQL) |
| HTTP behaviour, including the OpenAPI contract | `tests/Functional` |
| Schema | `tests/Migrations` |
| Tracker behaviour | `services/tracker/test` (Vitest + happy-dom) |
| Browser behaviour | `services/e2e` (Playwright) |
| Deploy console | `deploy/manual/tests` |

Details and coverage expectations: [testing.md](testing.md).
