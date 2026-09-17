# Dashboard (`services/dashboard`)

Nuxt 4 + Nuxt UI 4 single-page app for the self-hosted analytics service.
It is built from the MIT-licensed [`nuxt-ui-templates/dashboard`](https://github.com/nuxt-ui-templates/dashboard)
template (pinned commit in `TEMPLATE_COMMIT`, licence text in `TEMPLATE_LICENSE`).

* `ssr: false`, `nitro.preset: 'static'` — `pnpm generate` writes `.output/public`.
* The API lives on the same origin under `/api/v1`; the tracker under `/t`.
  In dev both are proxied to `ANALYTICS_API_ORIGIN` (default `http://localhost:8080`).
* Types in `app/types/api.d.ts` are generated from `docs/api/openapi.yaml`.
* Interface languages: English and Italian (`i18n/locales/*.json`, strategy `no_prefix`).

## Commands

| Command | Purpose |
|---|---|
| `pnpm dev` | Dev server with the `/api` and `/t` proxies |
| `pnpm generate` | Static build into `.output/public` |
| `pnpm generate:api` | Build, copy into `../api/public`, write CSP script hashes to `../api/config/csp.php` |
| `pnpm lint` / `pnpm typecheck` | ESLint / `nuxt typecheck` |
| `pnpm test` / `pnpm test:coverage` | Vitest (`@nuxt/test-utils` + happy-dom) |
| `pnpm openapi-types` | Regenerate `app/types/api.d.ts` |
| `pnpm openapi-types:check` | Fail when the generated types are stale |
| `pnpm i18n:check` | Fail when the locale files have different key sets |

## Layout

* `app/composables` — `useApi` (CSRF, problem+json errors, 401 handling), `useAuth`, `useSites`,
  `useReportQuery` (state ↔ URL), `useReport` (data loading, cursor paging, CSV), `useFormatters`.
* `app/components/report` — shared toolbar, period picker, filter chips and `ReportTable`.
* `app/pages` — reports, settings, authentication and the global admin area.
* `test/unit` — pure utilities, i18n parity and the "no raw text in templates" check.
* `test/nuxt` — composables and components with mocked endpoints.
