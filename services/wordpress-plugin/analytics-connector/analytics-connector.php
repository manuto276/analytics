<?php
/**
 * Plugin Name:       Analytics
 * Plugin URI:        https://github.com/manuto276/analytics
 * Description:       The site's visits, sources and conversions, measured by your own analytics service — the tracker and its consent banner on every page, and a dashboard in WordPress.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Analytics contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       analytics-connector
 * Domain Path:       /languages
 * Update URI:        https://github.com/manuto276/analytics
 *
 * The slug, the option and the prefix are the connector's (analytics-connector), so a site
 * that ran it updates in place and keeps its settings. `Update URI` stops wordpress.org from
 * offering an unrelated plugin with the same slug as an "update": releases come from GitHub.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'ANALYTICS_CONNECTOR_VERSION', '1.0.0' );
define( 'ANALYTICS_CONNECTOR_FILE', __FILE__ );
define( 'ANALYTICS_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/settings.php';      // the option, its defaults and sanitising; who may do what
require_once __DIR__ . '/includes/frontend.php';      // the tracker, the content key, the consent links
require_once __DIR__ . '/includes/conversions.php';   // server-side conversions
require_once __DIR__ . '/includes/secrets.php';       // the API key: wp-config.php, or stored encrypted
require_once __DIR__ . '/includes/client.php';        // WordPress → the service: reports, cached
require_once __DIR__ . '/includes/status.php';        // is the tracker served, does the key work
require_once __DIR__ . '/includes/rest-admin.php';    // analytics-connector/v1/admin: the dashboard's API
require_once __DIR__ . '/includes/admin-app.php';     // the menu, the React app, the dashboard widget

add_action( 'init', 'analytics_connector_init' );
add_action( 'wp_enqueue_scripts', 'analytics_connector_enqueue_scripts' );
add_action( 'wp_head', 'analytics_connector_content_meta', 1 );
add_filter( 'nav_menu_link_attributes', 'analytics_connector_menu_link_attributes' );
add_action( 'admin_init', 'analytics_connector_maybe_upgrade' );
add_action( 'admin_menu', 'analytics_connector_admin_menu' );
add_action( 'wp_dashboard_setup', 'analytics_connector_dashboard_widget' );
add_action( 'rest_api_init', 'analytics_connector_register_admin_routes' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'analytics_connector_action_links' );

register_activation_hook( __FILE__, 'analytics_connector_install_capabilities' );
