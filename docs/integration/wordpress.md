# WordPress integration

`services/wordpress-plugin/analytics-connector` is **Analytics for WordPress**: an optional WordPress plugin (GPL-2.0-or-later, with its own `LICENSE`). It lives in this repository ([ADR 0008](../architecture/adr/0008-separate-wordpress-plugin.md), amended by [ADR 0009](../architecture/adr/0009-wordpress-plugin-dashboard.md)) and has its own [README](../../services/wordpress-plugin/analytics-connector/README.md) and releases: tags `wordpress-plugin-vX.Y.Z`, each with the zip WordPress installs.

A plain `<script>` in the theme also works. The plugin exists for three things:
- tracking loads on every public page, whatever the theme or page builder;
- it stays versioned with the tracker and the API;
- with an API key that has `reports:read`, the site's statistics show in WordPress.

It never stores analytics data in WordPress. It stores:
- its settings (`analytics_connector_settings`);
- the API key, encrypted (`analytics_connector_api_key`), unless `wp-config.php` defines it;
- its version and a cache generation;
- the `analytics_view` and `analytics_manage` capabilities.

All of these are removed on uninstall. Reports are cached in transients for 60 seconds (10 for realtime).

## Install and configure

1. Upload `analytics-connector-X.Y.Z.zip` from the [releases](https://github.com/manuto276/analytics/releases?q=wordpress-plugin) (Plugins → Add New → Upload) and activate it.
2. **Analytics → Settings** (requires `analytics_manage`, given to administrators). It is a React screen saving through `analytics-connector/v1/admin/settings`, behind the REST nonce:

| Setting | Default | Notes |
|---|---|---|
| Service address | – | e.g. `https://stats.example.net`; trailing slash removed |
| Public key | – | must match `^pk_[A-Za-z0-9]{21}$`, otherwise refused (400) |
| How the tracker is loaded | direct | `direct` → `{service}/t/{key}.js`; `proxy` → `{home}{proxy path}{key}.js` |
| Proxy path | `/stats/` | normalised to `/path/` |
| Not counted: logged-in users who can | `edit_posts` | empty = track everyone, including logged-in editors |
| JavaScript name | `analytics` | must be a JS identifier and match the site's global name in the dashboard |
| API key | – | `ak_…`, with `reports:read` for the Overview and `conversions:write` for server-side conversions |

3. The API key can be pasted in the screen, where it is stored with sodium `secretbox`, keyed from `AUTH_KEY` and `SECURE_AUTH_SALT`. Or it can be kept out of the database; the constant wins:

```php
define( 'ANALYTICS_CONNECTOR_API_KEY', 'ak_…' );
```

The key never reaches the browser. The screens show only its prefix (`ak_1a2b3c4d_…`).

## The dashboard in WordPress

**Analytics → Overview** (`analytics_view`: administrators and editors) and a widget on the WordPress dashboard. The browser calls only WordPress:

```
browser → GET /wp-json/analytics-connector/v1/admin/reports/{name}?period=…   (REST nonce, analytics_view)
WordPress → GET {service}/api/v1/server/sites/{publicKey}/reports/{name}      (Authorization: Bearer ak_…, no redirects)
```

- **Report names:** overview, timeseries, pages, sources, tech, countries, events, goals, conversions, consent, realtime.
- **Parameters:** `period`, `from`/`to`, `interval`, `compare`, `kind`, `group` and `limit` (at most 100).
- Both lists are whitelisted before anything reaches the service. The answer is `{ data, meta }`, with `meta` reduced to range, interval, time zone, currency and availability.
- The service's refusals become sentences:

| The service answers | WordPress answers | Meaning |
|---|---|---|
| 401 | 409 | the key was revoked or mistyped |
| 403 | 409 | the key lacks `reports:read` |
| 404 | 409 | the site is unknown for this key, or the service predates `reports:read` |
| 422, 429 | the same | a bad period; rate limited |
| 5xx, or unreachable | 502 | the service is down |

The service's own text is never shown. Errors are cached for 30 seconds, so a service that is down does not cost every admin page the whole timeout.

Analytics → Settings → **The site on the service** reads the tracker script (`window.__an_cfg`) to show:
- the cookie level;
- whether a consent banner is published, with its version and languages;
- the automatic events.

It then checks the key with today's overview.

## What the plugin outputs

On `wp_enqueue_scripts` (when configured and the user is not excluded) it calls `wp_enqueue_script()` with `['strategy' => 'defer', 'in_footer' => false]` and an inline `before` stub, which renders as:

```html
<script>window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}</script>
<script src="https://stats.example.net/t/pk_XXXXXXXXXXXXXXXXXXXXX.js" id="analytics-connector-js" defer data-wp-strategy="defer"></script>
```

No `?ver=` is appended: the service versions the script with `ETag`.

### Consent settings links

All variants render `<a href="#analytics-consent" data-analytics-consent>…</a>`; the tracker opens the consent preferences on click.

- Shortcode: `[analytics_consent_link label="Cookie settings"]` (label escaped, default "Cookie settings").
- Block: `analytics-connector/consent-link` (dynamic, server-rendered through the shortcode; label in the sidebar).
- Menu: a Custom Link with URL `#analytics-consent` gets `data-analytics-consent="true"` via `nav_menu_link_attributes`.

### Content key

```php
add_filter( 'analytics_connector_content_key', function ( $key ) {
	return is_singular( 'post' ) ? 'author:' . get_post_field( 'post_author', get_queried_object_id() ) : $key;
} );
```

Default `null` → nothing printed. A non-empty value prints `<meta name="analytics:content" content="author:42">` in `wp_head` (see the [tracker contract](../architecture/tracker.md) and [tracker.md](tracker.md)).

## Server-side conversions

```php
analytics_connector_track_conversion( string $name, array $args = [] ): bool
```

```php
analytics_connector_track_conversion( 'purchase', [
	'id'           => 'order-8812',                 // idempotency id; default wp_generate_uuid4()
	'occurred_at'  => time(),                       // int or DateTimeInterface; default now
	'customer_ref' => 'opaque-123',                 // optional
	'value'        => [ 'amount_minor' => 4900, 'currency' => 'EUR' ],
	'props'        => [ 'plan' => 'pro' ],
	// 'visitor_id' => '…',                         // default: an_vid cookie
] );
```

Sends `POST {service}/api/v1/server/sites/{publicKey}/conversions` (see [server-side conversions](server-side-conversions.md) and the [API reference](../api/conversions.md)) with `Authorization: Bearer <the API key>` (the constant, or the key saved in Settings), `Content-Type: application/json`, `blocking => false`, `timeout => 2`:

```json
{"id":"order-8812","name":"purchase","occurred_at":"2026-09-17T10:00:00Z","visitor_id":"AbCdEfGhIjKlMnOpQr_-12",
 "customer_ref":"opaque-123","value":{"amount_minor":4900,"currency":"EUR"},"props":{"plan":"pro"}}
```

- `visitor_id` comes from the `an_vid` cookie when it matches `^[A-Za-z0-9_-]{22}$` (invalid values are dropped). The cookie exists only after the visitor accepted cookies and is visible to WordPress only on the same registrable domain as the tracked pages (see [server-side-conversions.md](server-side-conversions.md)). Without it the conversion is still counted, unattributed; `customer_ref` can still attribute it later.
- Returns `false` when the service URL/public key are not configured, the constant is missing/empty, or `wp_remote_post()` returns a `WP_Error`. Because the request is non-blocking, `true` means "dispatched", not "accepted"; always pass a stable `id` so retries are idempotent.

## Page caches and proxies

- **Do not vary or bypass the cache on `an_*` cookies** (`an_consent`, `an_vid`, `an_sid`). They are read by the tracker in the browser; the HTML is identical for every anonymous visitor. Varnish: strip them in `vcl_recv`, e.g. `set req.http.Cookie = regsuball(req.http.Cookie, "(^|;\s*)an_[a-z]+=[^;]*", "");`. WordPress cache plugins: do not list them among cookies that vary or bypass the cache. The full rules are in [caching-proxies.md](caching-proxies.md).
- **Bypass the proxy path** (proxy mode, e.g. `/stats/`): exclude it from full-page caching, CDN HTML rules and optimisation plugins, and forward it untouched ([first-party proxy](first-party-proxy.md)):

  ```nginx
  location /stats/ {
      proxy_pass https://stats.example.net/t/;
      proxy_set_header Host stats.example.net;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_ssl_server_name on;
  }
  ```

  The analytics service must list the proxy IP in `TRUSTED_PROXIES`; see [first-party-proxy.md](first-party-proxy.md).
- **Do not combine, inline or delay the tracker script** in JS optimisation plugins: the tracker derives its endpoint from its own `src`.
- Logged-in exclusion is evaluated per request; page caches normally do not serve cached HTML to logged-in users.

## Tests

```sh
make test-wordpress           # from the repository root: the unit tests, then the smoke test

cd services/wordpress-plugin/analytics-connector/tests
composer install
vendor/bin/phpunit            # PHPUnit 13 + Brain Monkey, no WordPress needed
./smoke/smoke.sh              # Docker: WordPress php8.4 + MariaDB 11 + wp-cli
```

The unit tests cover:
- settings and capabilities;
- the key's encryption, including new salts and the constant winning;
- the client: the whitelist, the Bearer header, no redirects, the cache and its flush, every refusal;
- the tracker check and the REST routes.

The smoke test (nightly CI, see [../development/testing.md](../development/testing.md)) installs WordPress, activates the plugin, sets the option with wp-cli and checks:
- the deferred script tag in `<head>`, after the stub;
- no PHP notices with `WP_DEBUG`;
- the shortcode and block output;
- the proxy mode URL;
- the menu attribute;
- the content meta tag;
- `analytics_connector_track_conversion()` returning `false` without an API key.

Environment overrides: `WP_SMOKE_PORT` (default 8089), `WP_SMOKE_WP_IMAGE`, `WP_SMOKE_CLI_IMAGE`, `WP_SMOKE_DB_IMAGE`, `KEEP=1`.

The admin screens have an end-to-end test (Playwright and axe, `npm run test:e2e` in the plugin) that runs on the WordPress workspace's dev site with the service mocked. The nightly job builds the admin app and checks that the committed `build/` is its build. The release workflow (`.github/workflows/wordpress-plugin-release.yml`, on `wordpress-plugin-v*` tags) runs all of the above and publishes the zip.
