=== Analytics Connector ===
Contributors: analytics
Tags: analytics, statistics, privacy, consent, cookies
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WordPress site to a self-hosted Analytics service: tracker script, consent settings links, content keys and server-side conversions.

== Description ==

Analytics Connector is an optional helper for sites measured by a self-hosted Analytics service. It does not collect or store anything in WordPress: the tracker, the consent banner and all data live on the analytics service.

Features:

* Loads the tracker on every public page, in the `<head>` with `defer`, whatever theme or page builder is used, preceded by the small queue stub so that `analytics.track()` calls made before the script loads are not lost.
* Loads the script directly from the service (`https://stats.example.net/t/pk_….js`) or through a first-party proxy path on your own domain (`/stats/pk_….js`).
* Does not track logged-in users who have a chosen capability (default `edit_posts`).
* "Cookie settings" links that reopen the consent preferences: shortcode, block and menu item.
* Content key meta tag (for example per-author reports) through a filter.
* `analytics_connector_track_conversion()` to send server-side conversions (orders, sign-ups) linked to the visitor who consented to cookies.

== Installation ==

1. Copy the `analytics-connector` folder to `wp-content/plugins/` and activate the plugin.
2. Go to **Settings → Analytics**.
3. Enter the **Service URL** (e.g. `https://stats.example.net`) and the site **Public key** (`pk_` followed by 21 letters or digits) from the analytics dashboard.
4. Choose the **Load mode**:
   * **Direct**: the script loads from `{service URL}/t/{public key}.js`.
   * **First-party proxy path**: the script loads from `{proxy path}{public key}.js` on this site (e.g. `/stats/pk_….js`). Your web server must forward that path to `https://stats.example.net/t/` (see "First-party proxy" below).
5. Optionally change **Do not track users who can** (empty = track everyone) and the **JavaScript global** (default `analytics`; it must match the global name configured for the site in the analytics dashboard).

The server-side conversions API key is never stored in the database. Define it in `wp-config.php`:

`define( 'ANALYTICS_CONNECTOR_API_KEY', 'your-api-key-with-conversions-write-scope' );`

== Frequently Asked Questions ==

= How do I add a link to reopen the cookie preferences? =

* Shortcode: `[analytics_consent_link]` or `[analytics_consent_link label="Cookie settings"]`.
* Block: insert **Consent settings link** and set its label in the block sidebar.
* Menu: add a **Custom Link** with URL `#analytics-consent`. The plugin adds the `data-analytics-consent` attribute to it.

All of them render a link with `href="#analytics-consent"` and `data-analytics-consent`, which the tracker turns into a "reopen preferences" button.

= How do I set a content key? =

Return a key from the `analytics_connector_content_key` filter. When the value is not empty the plugin prints `<meta name="analytics:content" content="…">` in the head. Example, group single posts by author:

`add_filter( 'analytics_connector_content_key', function ( $key ) {
    return is_singular( 'post' ) ? 'author:' . get_post_field( 'post_author', get_queried_object_id() ) : $key;
} );`

= How do I send a server-side conversion? =

`analytics_connector_track_conversion( string $name, array $args = array() ): bool`

`add_action( 'woocommerce_payment_complete', function ( $order_id ) {
    $order = wc_get_order( $order_id );
    analytics_connector_track_conversion( 'purchase', array(
        'id'           => 'order-' . $order_id,
        'customer_ref' => (string) $order->get_customer_id(),
        'value'        => array( 'amount_minor' => (int) round( $order->get_total() * 100 ), 'currency' => $order->get_currency() ),
        'props'        => array( 'plan' => 'pro' ),
    ) );
} );`

Arguments (all optional):

* `id` – idempotency id; sending the same id twice counts once. Default: a random UUID, so pass a stable id whenever you have one.
* `occurred_at` – Unix timestamp or `DateTimeInterface`. Default: now. Sent as ISO 8601 UTC.
* `visitor_id` – default: the `an_vid` cookie, which exists only after the visitor accepted cookies and is readable when WordPress runs on the same registrable domain as the tracked pages. Invalid values are ignored; without a visitor id the conversion is still counted, unattributed.
* `customer_ref` – opaque customer reference (hashed by the service), used to attribute repeat conversions.
* `value` – `array( 'amount_minor' => 4900, 'currency' => 'EUR' )`.
* `props` – up to 10 scalar properties.

The request is a non-blocking `POST {service URL}/api/v1/server/sites/{public key}/conversions` with a 2 second timeout, so it never slows the page down and its result is not checked. The function returns `false` when the plugin is not configured or `ANALYTICS_CONNECTOR_API_KEY` is not defined.

= Does it work with page caches? =

Yes, with the settings below. See "Page caches".

== Page caches ==

The script tag and the stub are identical for every anonymous visitor, so cached pages are fine. Consent state lives in cookies read by the tracker in the browser, never by WordPress.

* **Do not vary the cache on `an_*` cookies.** `an_consent`, `an_vid` and `an_sid` are written by the tracker; they must not create separate cache entries or bypass the cache. In Varnish strip them from the cache key (e.g. remove `an_[a-z]+=[^;]*` from `req.http.Cookie` in `vcl_recv`); in WP Rocket, W3 Total Cache, LiteSpeed Cache or similar do not add them to "cookies that vary / bypass the cache".
* **Bypass the proxy path.** In proxy mode the proxy path (e.g. `/stats/`) must be excluded from full-page caching, minification, combination and CDN HTML rules and forwarded untouched to the analytics service. The script itself is already cached correctly by the service (`ETag`, short max-age), and event requests are `POST`s.
* **Do not combine or inline the tracker script.** Exclude `/t/pk_` and the proxy path from JavaScript optimisation ("combine", "delay JavaScript execution", "load JS deferred" rewriters), otherwise the tracker cannot derive its endpoint from its own `src`.
* **Logged-in exclusion.** "Do not track users who can" works per request; caches normally do not serve cached pages to logged-in users, so no extra setting is needed.

= First-party proxy =

Example nginx configuration on the WordPress server:

`location /stats/ {
    proxy_pass https://stats.example.net/t/;
    proxy_set_header Host stats.example.net;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_ssl_server_name on;
}`

The analytics service must list the proxy IP in `TRUSTED_PROXIES`.

== Changelog ==

= 0.1.0 =
* First release.
