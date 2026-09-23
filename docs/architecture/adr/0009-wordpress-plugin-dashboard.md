# 0009. The WordPress plugin grows an admin UI and a reports dashboard

- **Status:** accepted
- **Date:** 2026-09-23
- **Deciders:** repository maintainers

## Context

[0008](0008-separate-wordpress-plugin.md) made the WordPress plugin a small, optional connector: load
the tracker, offer the consent link, send conversions. It said the plugin "stays small and does only
what the platform makes awkward".

Two things have changed.

- **The sites that run it want their numbers where they work.** The people who run a small
  business's WordPress site do not have, and should not need, an account on the analytics dashboard.
  They want to see visits, pages, sources and conversions in the WordPress admin.
- **The workspace the plugin lives next to has a standard for WordPress plugins.** Every other
  plugin there (Agenda, Postino, Voci, Chiaro) has a React admin UI built with `@wordpress/scripts`,
  a README with its own identity, an Italian translation and releases with a zip. A connector
  configured through a bare settings form, never released, looks and installs like something else.

The API offered no way to read reports with an API key: the reporting routes accept only a
dashboard session (same origin, `SameSite=Lax` cookie, no CORS), and keys had two scopes,
`conversions:write` and `stats:read`.

## Decision

**The plugin stays in this repository**, in `services/wordpress-plugin/analytics-connector`,
GPL-2.0-or-later, outside the release tarball and the images, exactly as 0008 decided. It is now
called **Analytics** in WordPress; its slug, option, prefix and constants do not change, so an
installed copy updates in place.

**It has an admin UI and a dashboard.**

- A React app on `@wordpress/scripts` (the workspace's pattern), with an Overview and the Settings.
- The Overview shows the site's reports — headline metrics, a time series, pages, sources,
  countries, devices, events, realtime — read from the service **by WordPress, server-side**, with an
  API key that has the new scope `reports:read`, cached briefly in transients. The browser talks only
  to WordPress's REST API, behind a nonce and a capability; the key never reaches it.
- Without such a key the plugin does what it did before, and the Overview says how to get one.

**The API gains `reports:read`** (`services/api/src/Conversions/Domain/ApiKey.php`) and the routes
`/api/v1/server/sites/{publicKey}/reports/{report}` (`ReportingModule::serverRoutes()`). They are
served by the same `ReportsController` as the dashboard's routes: `ApiKeyAuthMiddleware` resolves the
site from the public key and puts it where the session routes do. Only the reports that mean
something without the dashboard's own configuration are exposed.

**Releases are the plugin's own**, on tags `wordpress-plugin-vX.Y.Z` (the service keeps `v*`),
built and published by `.github/workflows/wordpress-plugin-release.yml`.

## Consequences

- The API is still free of WordPress: the new routes are generic ("a server with a key reads its
  site's reports") and any CMS can use them.
- The plugin is no longer "under 400 lines": it has a build step (`npm run build`, output committed
  in `build/`), JavaScript translations and end-to-end tests. The nightly job builds it before its
  tests.
- A plugin with a dashboard needs a service that has `reports:read`. Older services answer `404` on
  the report routes; the plugin treats that like a missing scope and says so. Sending events and
  conversions has no such requirement, so 0008's promise holds for them: a service upgrade never
  requires a plugin upgrade.
- A leaked `reports:read` key discloses one site's aggregate reports — what a viewer of that site
  sees — and nothing else. `docs/operations/incident-response.md` lists it.
- Tests pinning it: `tests/Functional/Reporting/ServerReportsTest.php` (the same bodies as the session
  routes, the scope, the site binding) and the plugin's own suites.
