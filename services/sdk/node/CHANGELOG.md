# Changelog

All notable changes to `@manuto276/analytics-node`. Versions follow
[Semantic Versioning](https://semver.org). Releases are tagged `sdk-node-vX.Y.Z`.

## [0.1.0] — 2026-09-23

First release.

- `createClient({ serviceUrl, apiKey, publicKey, fetch?, timeoutMs?, retries? })` for the server
  API of one site.
- `conversions.send(one | many)` with the WordPress plugin's rules: a UUID `id` by default,
  `occurred_at` in ISO 8601 UTC (default now), `visitor_id` dropped unless it has the `an_vid`
  format, `value` as an integer amount in minor units with an ISO 4217 currency. Lists longer than
  100 are sent in chunks.
- `content.stats(contentKey, { days })` and `reports.<name>(params)` for every
  `/server/sites/{publicKey}/reports/*` route, typed from `docs/api/openapi.yaml`.
- `AnalyticsApiError` from RFC 9457 problem documents (`status`, `code`, `title`, `detail`,
  `errors`, `retryAfter`), also for network errors and timeouts.
- Retries on network errors, timeouts, 429 and 5xx, honouring `Retry-After`.
- `visitorIdFromCookie()` and `visitorIdFromRequest()` for Node, Express and Fetch requests.
- Node 18 or later, ES modules, CommonJS and type declarations; no runtime dependencies; MIT.
