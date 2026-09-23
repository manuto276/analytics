<?php
/**
 * The admin app's API, `analytics-connector/v1/admin`:
 *
 *   GET  settings         the settings, and what the screen may know of the API key   analytics_manage
 *   POST settings         save them; paste or remove the key                          analytics_manage
 *   GET  status           the tracker and the key, checked against the service        analytics_manage
 *   GET  reports/{name}   one report, through client.php                              analytics_view
 *
 * Behind the REST nonce (the app sends it through apiFetch) and the plugin's capabilities.
 * The key goes in, never out.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/** Registers the routes. */
function analytics_connector_register_admin_routes(): void {
	$ns     = 'analytics-connector/v1';
	$manage = static fn (): bool => current_user_can( 'analytics_manage' );

	register_rest_route(
		$ns,
		'/admin/settings',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'analytics_connector_rest_get_settings',
				'permission_callback' => $manage,
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'analytics_connector_rest_save_settings',
				'permission_callback' => $manage,
			),
		)
	);
	register_rest_route(
		$ns,
		'/admin/status',
		array(
			'methods'             => 'GET',
			'callback'            => static fn () => rest_ensure_response( analytics_connector_status() ),
			'permission_callback' => $manage,
		)
	);
	register_rest_route(
		$ns,
		'/admin/reports/(?P<name>[a-z]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'analytics_connector_rest_report',
			'permission_callback' => static fn (): bool => current_user_can( 'analytics_view' ),
		)
	);
}

/** The settings screen's data. */
function analytics_connector_rest_settings_payload(): array {
	return array(
		'settings' => analytics_connector_get_settings(),
		'api_key'  => array(
			'hint'        => analytics_connector_api_key_hint(),
			'from_config' => analytics_connector_api_key_from_config(),
		),
		'site_url' => home_url( '/' ),
	);
}

/** GET settings. */
function analytics_connector_rest_get_settings(): WP_REST_Response {
	return rest_ensure_response( analytics_connector_rest_settings_payload() );
}

/**
 * POST settings: the settings as the screen edits them, and optionally `api_key` (a new key)
 * or `remove_api_key`.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function analytics_connector_rest_save_settings( WP_REST_Request $request ) {
	$input = (array) $request->get_json_params();

	$public_key = trim( (string) ( $input['public_key'] ?? '' ) );
	if ( '' !== $public_key && ! analytics_connector_is_valid_public_key( $public_key ) ) {
		return new WP_Error( 'analytics_invalid_public_key', __( 'The public key must be “pk_” followed by 21 letters or digits.', 'analytics-connector' ), array( 'status' => 400 ) );
	}

	$api_key = trim( (string) ( $input['api_key'] ?? '' ) );
	if ( '' !== $api_key && ! analytics_connector_is_valid_api_key( $api_key ) ) {
		return new WP_Error( 'analytics_invalid_api_key', __( 'That is not an API key: it starts with “ak_” and is 55 characters long. Copy it again from the analytics dashboard.', 'analytics-connector' ), array( 'status' => 400 ) );
	}
	if ( ( '' !== $api_key || ! empty( $input['remove_api_key'] ) ) && analytics_connector_api_key_from_config() ) {
		return new WP_Error( 'analytics_key_in_config', __( 'The API key is set in wp-config.php: change it there.', 'analytics-connector' ), array( 'status' => 409 ) );
	}

	$before = analytics_connector_get_settings();
	$after  = analytics_connector_sanitize_settings( $input );
	update_option( 'analytics_connector_settings', $after );

	if ( '' !== $api_key ) {
		analytics_connector_set_api_key( $api_key );
	} elseif ( ! empty( $input['remove_api_key'] ) ) {
		analytics_connector_set_api_key( '' );
	} elseif ( $before['service_url'] !== $after['service_url'] || $before['public_key'] !== $after['public_key'] ) {
		analytics_connector_flush_reports();
	}

	return rest_ensure_response( analytics_connector_rest_settings_payload() );
}

/**
 * GET reports/{name}: the report, or its error in words with the service's status mapped.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error
 */
function analytics_connector_rest_report( WP_REST_Request $request ) {
	$name   = (string) $request['name'];
	$params = analytics_connector_clean_report_params( $name, $request->get_query_params() );
	if ( null === $params ) {
		return new WP_Error( 'analytics_unknown_report', __( 'Unknown report.', 'analytics-connector' ), array( 'status' => 404 ) );
	}

	$report = analytics_connector_report( $name, $params );
	if ( is_wp_error( $report ) ) {
		return $report;
	}

	return rest_ensure_response(
		array(
			'data' => $report['data'],
			'meta' => array_intersect_key( (array) ( $report['meta'] ?? array() ), array_flip( array( 'range', 'compare_range', 'interval', 'timezone', 'currency', 'availability', 'generated_at' ) ) ),
		)
	);
}
