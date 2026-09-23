<?php
/**
 * Removes what the plugin stored when it is deleted: the settings, the encrypted API key,
 * the version, the cache generation, and the two capabilities. Cached reports are
 * transients: they expire on their own.
 *
 * @package AnalyticsConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'analytics_connector_settings', 'analytics_connector_api_key', 'analytics_connector_version', 'analytics_connector_cache_generation' ) as $analytics_connector_option ) {
	delete_option( $analytics_connector_option );
}
foreach ( wp_roles()->role_objects as $analytics_connector_role ) {
	$analytics_connector_role->remove_cap( 'analytics_view' );
	$analytics_connector_role->remove_cap( 'analytics_manage' );
}
