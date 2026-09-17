# 0008. A separate, optional WordPress plugin

- **Status:** accepted
- **Date:** 2026-09-17
- **Deciders:** repository maintainers

## Context

A large share of tracked sites run WordPress. On WordPress, "add this `<script>` to every page" is not
a one-line job: themes are replaced, page builders own the header, child themes get overwritten, and
caching plugins rewrite or defer scripts. A consent-settings link has to be available as a shortcode,
a block and a menu item, because those are the three places editors look.

At the same time the service must stay generic. It is not a WordPress product, it must work for any
site, and nothing WordPress-specific belongs in the API or the tracker.

The options were: no plugin at all (paste a snippet into the theme); a plugin inside the backend
repository but shipped in the release tarball; or a separate plugin directory with its own lifecycle.

## Decision

`services/wordpress-plugin/analytics-connector` is a standalone WordPress plugin, optional, with its
own `LICENSE` (**GPL-2.0-or-later**, as the WordPress ecosystem expects and as the plugin directory
requires). It is not part of the release tarball or of any container image.

It stays small and does only what the platform makes awkward:

- a settings page (service URL, public key, direct or proxy load mode, proxy path, a capability whose
  holders are not tracked, the JavaScript global name), stored in one option and deleted on uninstall;
- `wp_enqueue_scripts` with `strategy: defer` plus the queue stub, so the snippet survives theme
  changes;
- the consent link in three shapes: `[analytics_consent_link]`, the `analytics-connector/consent-link`
  block, and `nav_menu_link_attributes` on a `#analytics-consent` custom link;
- the `analytics_connector_content_key` filter, printed as `<meta name="analytics:content">`;
- `analytics_connector_track_conversion()`, which reads the `an_vid` cookie and posts to the
  conversions API non-blocking, with the key taken from the `ANALYTICS_CONNECTOR_API_KEY` constant.

## Consequences

- The backend and the tracker contain no WordPress code and no WordPress assumptions. The plugin talks
  only to the public HTTP contract, so it can be replaced by a plain `<script>` tag at any time.
- Two licences in one repository (AGPL-3.0-or-later overall, GPL-2.0-or-later for the plugin
  directory). That is deliberate and recorded in `NOTICE`; see also
  [0006](0006-tracker-license.md).
- The plugin is versioned with the tracker and the API but released separately: a service upgrade
  never requires a plugin upgrade, because the payload contract is versioned.
- It needs its own test setup — PHPUnit with Brain Monkey (no WordPress install) plus a Docker smoke
  test against a real WordPress, run nightly rather than on every pull request.
- Page caches remain the integrator's problem, which is why
  [../../integration/caching-proxies.md](../../integration/caching-proxies.md) and
  [../../integration/wordpress.md](../../integration/wordpress.md) spell out the `an_*` cookie and
  proxy-path rules.
