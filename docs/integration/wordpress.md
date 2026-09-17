# WordPress integration

`services/wordpress-plugin/analytics-connector` is an optional, generic WordPress plugin (GPL-2.0-or-later, its own `LICENSE`; see [ADR 0008](../architecture/adr/0008-separate-wordpress-plugin.md)).
A plain `<script>` in the theme also works; the plugin exists so that tracking loads on every public page regardless of theme or page builder and stays versioned with the tracker and the API.

It stores only its settings (option `analytics_connector_settings`, deleted on uninstall). It never stores analytics data in WordPress.

## Install and configure

1. Copy `services/wordpress-plugin/analytics-connector` to `wp-content/plugins/` and activate it.
2. **Settings → Analytics** (requires `manage_options`; saved through the Settings API with its nonce):

| Setting | Default | Notes |
|---|---|---|
| Service URL | – | e.g. `https://stats.example.net`; trailing slash removed |
| Public key | – | must match `^pk_[A-Za-z0-9]{21}$`, otherwise rejected and the previous key kept |
| Load mode | direct | `direct` → `{service}/t/{key}.js`; `proxy` → `{home}{proxy path}{key}.js` |
| Proxy path | `/stats/` | normalised to `/path/` |
| Do not track users who can | `edit_posts` | empty = track everyone, including logged-in editors |
| JavaScript global | `analytics` | must be a JS identifier and match the site's global name in the dashboard |

3. For server-side conversions add to `wp-config.php`:

```php
define( 'ANALYTICS_CONNECTOR_API_KEY', '…' ); // API key with the conversions:write scope
```

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

Sends `POST {service}/api/v1/server/sites/{publicKey}/conversions` (see [server-side conversions](server-side-conversions.md) and the [API reference](../api/conversions.md)) with `Authorization: Bearer ANALYTICS_CONNECTOR_API_KEY`, `Content-Type: application/json`, `blocking => false`, `timeout => 2`:

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
cd services/wordpress-plugin/analytics-connector/tests
composer install
vendor/bin/phpunit            # PHPUnit 13 + Brain Monkey, no WordPress needed
./smoke/smoke.sh              # Docker: WordPress php8.4 + MariaDB 11 + wp-cli
```

The smoke test (nightly CI, see [../development/testing.md](../development/testing.md)) installs WordPress, activates the plugin, sets the option with wp-cli and checks: deferred script tag in `<head>` after the stub, no PHP notices with `WP_DEBUG`, shortcode and block output, proxy mode URL, menu attribute, content meta tag, and `analytics_connector_track_conversion()` returning `false` without an API key.
Environment overrides: `WP_SMOKE_PORT` (default 8089), `WP_SMOKE_WP_IMAGE`, `WP_SMOKE_CLI_IMAGE`, `WP_SMOKE_DB_IMAGE`, `KEEP=1`.
