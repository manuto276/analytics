<?php
/**
 * Removes the plugin options when the plugin is deleted.
 *
 * @package AnalyticsConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'analytics_connector_settings' );
