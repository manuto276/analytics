<?php
/**
 * Plugin Name:       Analytics Connector
 * Plugin URI:        https://github.com/manuto276/analytics
 * Description:       Loads the self-hosted analytics tracker, adds consent settings links and sends server-side conversions.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Analytics contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       analytics-connector
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/conversions.php';

add_action( 'admin_init', 'analytics_connector_register_setting' );
add_action( 'admin_menu', 'analytics_connector_add_settings_page' );
add_action( 'init', 'analytics_connector_init' );
add_action( 'wp_enqueue_scripts', 'analytics_connector_enqueue_scripts' );
add_action( 'wp_head', 'analytics_connector_content_meta', 1 );
add_filter( 'nav_menu_link_attributes', 'analytics_connector_menu_link_attributes' );
