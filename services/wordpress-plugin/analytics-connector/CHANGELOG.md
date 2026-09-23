# Changelog

All notable changes to Analytics for WordPress. Versions follow
[Semantic Versioning](https://semver.org). Releases are tagged `wordpress-plugin-vX.Y.Z`.

## [1.0.0] — 2026-09-23

The connector becomes a plugin like the workspace's others: its own screens, a dashboard,
a release.

- **Named Analytics.** The folder, the option, the functions and the filters are unchanged,
  so a site running 0.1.0 updates in place and keeps its settings.
- **Analytics → Overview.**
  - the period in numbers against the previous one;
  - a line chart you can read with the keyboard;
  - pages, sources, countries, devices, events;
  - who is on the site now.

  It is read from the service with an API key that has **Read reports** (`reports:read`,
  new in the service). Without a key, it shows what to do, in order.
- **A widget on the WordPress dashboard:** visitors today and over 7 days.
- **Analytics → Settings**, in React, replacing Settings → Analytics:
  - the connection;
  - the API key, encrypted with a key derived from the site's salts, or in `wp-config.php`;
  - who is not counted;
  - a check of the tracker (the cookie level, the consent banner, the automatic events)
    and of the key.
- **The service's refusals in words**:
  - a revoked key;
  - a key without the permission;
  - an unknown site, or a service too old to have `reports:read`;
  - a service that is down.

  The service's own text never reaches the screen.
- **Capabilities** `analytics_view` (administrators, editors) and `analytics_manage`
  (administrators).
- **Conversions** use the saved key too, not only the `wp-config.php` constant.
- **Italian.**
- **Deleting the plugin** removes the key and the capabilities as well as the settings.

## [0.1.0] — 2026-09-17

- The tracker on every page, direct or through a first-party path.
- Logged-in staff not counted.
- Consent links: shortcode, block, menu.
- The content key filter.
- Server-side conversions.
