# Changelog

All notable changes to `@manuto276/analytics-browser`. Versions follow
[Semantic Versioning](https://semver.org). Releases are tagged `sdk-browser-vX.Y.Z`.

## [0.1.0] — 2026-09-23

First release.

- `load({ serviceUrl, publicKey, globalName?, proxyPath?, nonce? })` inserts the queue stub and the
  tracker's `<script defer>` once per global name, from the service or from a first-party proxy
  path, and returns a typed client: `track`, `pageview`, `setContent`, `getVisitorId`,
  `consent.{open,get,set,onChange,forget}` and a `ready` promise that never rejects.
- Calls made before the tracker loads are queued in the tracker's own stub and replayed by it.
- Server rendering: without `window`, `load()` inserts nothing and every call does nothing.
- `declare global` typings for `window.analytics` and `window.__analytics`, and
  `AnalyticsGlobals<'name'>` / `AnalyticsWindow<'name'>` for a custom global name.
- `@manuto276/analytics-browser/react`: `AnalyticsProvider`, `useAnalytics`, `useConsent`.
- `@manuto276/analytics-browser/vue`: `createAnalytics` plugin, `useAnalytics`, `useConsent`.
- The tracker's API types are generated from the tracker's source (`pnpm tracker-types`).
- ES modules, CommonJS and type declarations; no runtime dependencies; MIT.
