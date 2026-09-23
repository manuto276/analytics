# 0010. JavaScript SDKs: MIT packages on GitHub Packages

- **Status:** accepted
- **Date:** 2026-09-23
- **Deciders:** repository maintainers

## Context

Sites integrate the service in two ways today: the `<script>` snippet (`GET /t/{publicKey}.js`) in
the browser, and hand-written HTTP calls to `/api/v1/server/*` on their backends. Both work, and
neither is typed. Applications built with a bundler (React, Vue, Next.js, Nuxt) end up
re-implementing the snippet and its queue stub in a component, with `window.analytics` as `any`
and server rendering as a trap (no `window` there). Backends re-implement the conversion rules the
WordPress plugin already encodes (UUID ids, the `an_vid` format check, minor units, ISO 8601 UTC)
and the retry policy of [../../integration/server-side-conversions.md](../../integration/server-side-conversions.md).

A package that an application imports is different from the tracker in the way
[0006](0006-tracker-license.md) turns on: it is **bundled into other people's code**. Its source is
compiled into their JavaScript bundle or their server build, which they ship under their own
licence. A copyleft licence there is exactly the "does it reach my application?" question 0006
avoided for the tracker by keeping it a standalone `<script src>`. The usual answer for SDKs is a
permissive licence, and integrators check for it.

Where to publish: npmjs.com needs an organisation or a personal scope and a second account and
token to manage; GitHub Packages lives next to the repository, is published with the workflow's
own `GITHUB_TOKEN` (no long-lived secret), links each version to the commit and the release, and
keeps the `@manuto276` scope under the same ownership as the code. Its cost is on the consumer's
side: the scope has to be mapped to `https://npm.pkg.github.com` in `.npmrc`, and installing needs a
token with `read:packages`, even for a public package.

## Decision

Two packages live in `services/sdk/`, each a standalone pnpm project with its own lockfile, like
the tracker and the dashboard:

- **`@manuto276/analytics-browser`** (`services/sdk/browser`): `load()` inserts the queue stub and
  the tracker's `<script>` once, returns a typed client, is a no-op without `window`, and has React
  (`/react`) and Vue (`/vue`) bindings with React and Vue as optional peer dependencies.
- **`@manuto276/analytics-node`** (`services/sdk/node`): `createClient()` for conversions, content
  stats and every `/server/sites/{publicKey}/reports/*` route, with `AnalyticsApiError` from problem
  documents and retries honouring `Retry-After`. Node 18 or later, global `fetch`.

**Both are MIT**, with their own `LICENSE` (copyright "The analytics contributors") and
`"license": "MIT"` in `package.json`. The rest of the repository, **the tracker and the server
included, stays AGPL-3.0-or-later**; `NOTICE` lists the exception, as it does for the
GPL-2.0-or-later WordPress plugin ([0008](0008-separate-wordpress-plugin.md)).

**The SDKs contain no tracker code.** The browser package inserts the script the service serves; the
tracker (AGPL) is still downloaded from the service or its first-party proxy, as with the snippet.
What the browser package takes from the tracker is its **types**: `scripts/tracker-types.mjs`
generates `src/generated/tracker.ts` from `services/tracker/src/api.ts` and `consent.ts` (type
declarations only), and `pnpm tracker-types:check` plus a unit test fail when the committed copy
drifts. The Node package's types are generated from the `/server/*` paths of
`docs/api/openapi.yaml` with openapi-typescript (`pnpm openapi-types`, checked by
`pnpm openapi-types:check`), as the dashboard does for the whole document. Interfaces and a
published API contract are not the tracker's or the server's code, so the MIT packages stay free of
AGPL code.

**Registry: GitHub Packages** (`"publishConfig": { "registry": "https://npm.pkg.github.com" }`).

**Versioning is per package**, SemVer, independent of the service and of each other, on prefixed
tags: `sdk-browser-vX.Y.Z` and `sdk-node-vX.Y.Z` (the service keeps `v*`, the WordPress plugin
`wordpress-plugin-v*`). `.github/workflows/sdk-release.yml` resolves the package from the tag,
checks the tag against `package.json` and `CHANGELOG.md`, runs lint, typecheck, the generated-types
check, the tests with coverage, the build and the package checks (publint, are-the-types-wrong),
publishes the packed tarball with `npm publish`, and creates a GitHub release with the changelog
section as notes and the tarball attached (`--latest=false`, so the service's release stays
"latest").

## Consequences

- An application can bundle either package without any copyleft question; modifying and serving the
  tracker still triggers the AGPL's network clause, as 0006 says.
- Contributions to `services/sdk/` are MIT-licensed. Moving code from the tracker or the server into
  an SDK would need its contributors' agreement; the generated type files are the only thing that
  crosses, and they are declarations of a public contract.
- A change to the tracker's API or to the `/server/*` part of the API document fails the SDK's CI
  until the generated types are regenerated and, when the public surface changes, a new SDK version
  is released. That is the point: the SDKs cannot silently disagree with what they wrap.
- The browser SDK still depends on the service for the tracker: an old service works with a new SDK
  as long as the queue contract (`{q: [...]}` and dotted method names, `services/tracker/src/api.ts`)
  is unchanged, and that contract is now public API.
- Installing from GitHub Packages needs a token even for public packages. The docs
  ([../../integration/sdk-browser.md](../../integration/sdk-browser.md),
  [../../integration/sdk-node.md](../../integration/sdk-node.md)) and each package's README give
  the `.npmrc` line and the token scope. Moving to npmjs.com later means a new registry in
  `publishConfig` and the release workflow, and a note for existing users; the package names can
  stay.
- CI runs both packages on every push (`ci.yml`, job `sdk`), the nightly job runs the full suites
  and the Node package's built output on Node 18, 20 and 22 (`scripts/smoke.mjs`,
  `scripts/smoke.cjs`), and `make test-sdk` (part of `make ci`) runs them in the pinned node
  container. Tests pinning the behaviour: `services/sdk/browser/test/*.test.ts(x)` (stub insertion,
  SSR, queue replay against a fake tracker with the real contract, React and Vue bindings, the
  tracker-types drift test) and `services/sdk/node/test/*.test.ts` (conversion rules, batches,
  problem documents, retries with fake timers, cookie and request helpers, the report list against
  the API document).
