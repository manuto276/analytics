<?php
/**
 * Settings: the option, its defaults and sanitising; and who may see and change what.
 *
 * The screen that edits them is the React app (admin-app.php, src/views/Settings.js), which
 * saves through the REST API (rest-admin.php) with the same sanitising as before.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/** Default settings. */
function analytics_connector_default_settings(): array {
	return array(
		'service_url'     => '',
		'public_key'      => '',
		'mode'            => 'direct',
		'proxy_path'      => '/stats/',
		'skip_capability' => 'edit_posts',
		'global_name'     => 'analytics',
	);
}

/** Stored settings merged with the defaults. */
function analytics_connector_get_settings(): array {
	$stored = get_option( 'analytics_connector_settings', array() );

	return array_merge( analytics_connector_default_settings(), is_array( $stored ) ? $stored : array() );
}

/**
 * Whether a string is a site public key (pk_ followed by 21 letters or digits).
 *
 * @param mixed $key Candidate key.
 */
function analytics_connector_is_valid_public_key( $key ): bool {
	return is_string( $key ) && 1 === preg_match( '/^pk_[A-Za-z0-9]{21}$/D', $key );
}

/**
 * Sanitises submitted settings. An invalid public key is rejected and the previous one kept.
 *
 * @param mixed $input Raw (already unslashed) option value.
 */
function analytics_connector_sanitize_settings( $input ): array {
	$input  = is_array( $input ) ? $input : array();
	$output = analytics_connector_default_settings();

	$url                   = trim( (string) ( $input['service_url'] ?? '' ) );
	$output['service_url'] = '' === $url ? '' : untrailingslashit( esc_url_raw( $url, array( 'https', 'http' ) ) );

	$key = trim( (string) ( $input['public_key'] ?? '' ) );
	if ( '' === $key || analytics_connector_is_valid_public_key( $key ) ) {
		$output['public_key'] = $key;
	} else {
		$output['public_key'] = analytics_connector_get_settings()['public_key'];
		// The REST route refuses an invalid key before it gets here; the Settings API's
		// notice is for any other caller.
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				'analytics_connector_settings',
				'invalid_public_key',
				__( 'The public key must be "pk_" followed by 21 letters or digits. The previous key was kept.', 'analytics-connector' )
			);
		}
	}

	$output['mode'] = 'proxy' === ( $input['mode'] ?? '' ) ? 'proxy' : 'direct';
	$path                 = trim( (string) preg_replace( array( '#[^A-Za-z0-9/_-]#', '#/+#' ), array( '', '/' ), (string) ( $input['proxy_path'] ?? '' ) ), '/' );
	$output['proxy_path'] = '' === $path ? '' : '/' . $path . '/';
	$output['skip_capability'] = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $input['skip_capability'] ?? '' ) ) );
	$global                = trim( (string) ( $input['global_name'] ?? '' ) );
	$output['global_name'] = preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $global ) ? $global : 'analytics';

	return $output;
}

/**
 * Who may do what.
 *
 * - `analytics_view`: the dashboard (Analytics → Overview) and the dashboard widget.
 *   Administrators and editors: the people who write the site want to see who reads it.
 * - `analytics_manage`: the settings and the API key. Administrators.
 *
 * Two capabilities of the plugin's own rather than `edit_posts` / `manage_options`, so a site
 * can give the numbers to a role without giving it anything else (a members plugin, or
 * `$role->add_cap( 'analytics_view' )`).
 */
function analytics_connector_install_capabilities(): void {
	foreach ( array(
		'administrator' => array( 'analytics_view', 'analytics_manage' ),
		'editor'        => array( 'analytics_view' ),
	) as $role_name => $caps ) {
		$role = get_role( $role_name );
		if ( $role ) {
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}
	update_option( 'analytics_connector_version', ANALYTICS_CONNECTOR_VERSION, false );
}

/**
 * The capabilities on an update too: WordPress runs the activation hook when a plugin is
 * activated, not when a new zip replaces an active one.
 */
function analytics_connector_maybe_upgrade(): void {
	if ( get_option( 'analytics_connector_version' ) !== ANALYTICS_CONNECTOR_VERSION ) {
		analytics_connector_install_capabilities();
	}
}
