# @analytics/tracker

Dependency-free browser tracker and consent banner (TypeScript, bundled by esbuild as a minified ES2019 IIFE).
The contract (config `window.__an_cfg`, transport, cookies, JS API) is documented in
[`docs/architecture/tracker.md`](../../docs/architecture/tracker.md); the payload schema is
[`docs/api/tracking-payload.v1.schema.json`](../../docs/api/tracking-payload.v1.schema.json).

## Layout

```
src/index.ts        bootstrap: skips, prerender activation, wiring
src/config.ts       config types and shared globals
src/ids.ts          base64url random ids
src/cookies.ts      document.cookie access, domain probe, id deletion
src/consent.ts      consent state machine (unknown / accepted / rejected), cs/cu events, forget
src/collector.ts    queue, flush rules, pageview / event / engagement events
src/transport.ts    sendBeacon (text/plain) with fetch keepalive fallback + one retry
src/spa.ts          history patching, popstate, hashchange (hash routing)
src/dom.ts          declarative attributes (data-analytics-*)
src/api.ts          window[g] API, stub queue replay, global collision handling
src/banner/         shadow-DOM consent banner (banner, template, styles)
```

## Commands

Uses pnpm (`corepack pnpm …` works without a global install).

```sh
pnpm install --frozen-lockfile
pnpm build           # dist/tracker.js
pnpm build:api       # build + copy to ../api/resources/tracker/tracker.js
pnpm test            # Vitest + happy-dom
pnpm test:coverage   # v8 coverage, gates: lines >= 95 %, branches >= 90 %
pnpm size            # size-limit: dist/tracker.js <= 5.0 KB gzip (run after build)
pnpm lint            # ESLint (typescript-eslint, flat config)
pnpm typecheck       # tsc --noEmit
```

Test helpers live in `test/helpers.ts` (`makeConfig`, `installDom`, `advanceTime`, a fake cookie jar that
honours `Domain`, `Max-Age` and `Secure`, and payload validation against the JSON schema with Ajv).

Note: the tracker sends nothing when `navigator.webdriver` is true, so browser automation must
override it (e.g. a Playwright init script) to exercise real tracking.
