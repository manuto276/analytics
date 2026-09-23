<?php
/**
 * WordPress → the service: the site's reports, read with the API key.
 *
 *   GET {service}/api/v1/server/sites/{public key}/reports/{name}?{params}
 *   Authorization: Bearer ak_…
 *
 * Server to server, because the key is a secret and the service answers the browser only on
 * the tracker's routes. Answers are cached for a minute (realtime ten seconds), so a
 * dashboard left open or several people looking at it cost the service one call.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * The reports the dashboard may ask for, with the parameters each accepts and the values
 * allowed. Anything else never reaches the service.
 *
 * @return array<string, array<string, list<string>|string>>
 */
function analytics_connector_reports(): array {
	$range = array(
		'period' => array( 'today', 'yesterday', '7d', '30d', '90d', 'month', 'last_month', '12mo', 'year', 'custom' ),
		'from'   => 'date',
		'to'     => 'date',
	);
	$table = $range + array( 'limit' => 'limit' );

	return array(
		'overview'    => $range + array( 'compare' => array( 'none', 'previous_period', 'previous_year' ) ),
		'timeseries'  => $range + array(
			'interval' => array( 'hour', 'day', 'week', 'month' ),
			'compare'  => array( 'none', 'previous_period', 'previous_year' ),
		),
		'pages'       => $table + array( 'kind' => array( 'top', 'entry', 'exit' ) ),
		'sources'     => $table + array( 'group' => array( 'channel', 'source', 'referrer' ) ),
		'tech'        => $table + array( 'group' => array( 'device', 'browser', 'os' ) ),
		'countries'   => $table,
		'events'      => $table,
		'goals'       => $table,
		'conversions' => $table,
		'consent'     => $range,
		'realtime'    => array(),
	);
}

/**
 * The parameters of a report, kept only when the report accepts them and the value is allowed.
 *
 * @param array<string, mixed> $params Query parameters as received.
 * @return array<string, string>|null Null for an unknown report.
 */
function analytics_connector_clean_report_params( string $name, array $params ): ?array {
	$allowed = analytics_connector_reports()[ $name ] ?? null;
	if ( null === $allowed ) {
		return null;
	}

	$clean = array();
	foreach ( $allowed as $param => $rule ) {
		if ( ! isset( $params[ $param ] ) || ! is_scalar( $params[ $param ] ) ) {
			continue;
		}
		$value = (string) $params[ $param ];
		$ok    = match ( true ) {
			is_array( $rule ) => in_array( $value, $rule, true ),
			'date' === $rule  => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ),
			'limit' === $rule => ctype_digit( $value ) && (int) $value >= 1 && (int) $value <= 100,
			default           => false,
		};
		if ( $ok ) {
			$clean[ $param ] = $value;
		}
	}
	ksort( $clean );

	return $clean;
}

/**
 * A report from the service.
 *
 * @param array<string, string> $params Already cleaned by analytics_connector_clean_report_params().
 * @return array<string, mixed>|WP_Error The service's `{ data, meta }`, or an error in words.
 */
function analytics_connector_report( string $name, array $params = array() ) {
	$settings = analytics_connector_get_settings();
	$key      = analytics_connector_get_api_key();

	if ( '' === $settings['service_url'] || ! analytics_connector_is_valid_public_key( $settings['public_key'] ) ) {
		return new WP_Error( 'analytics_not_connected', __( 'Enter the service address and the public key in Analytics → Settings.', 'analytics-connector' ), array( 'status' => 409 ) );
	}
	if ( '' === $key ) {
		return new WP_Error( 'analytics_no_key', __( 'Paste an API key with the “Read reports” permission in Analytics → Settings to see the statistics here.', 'analytics-connector' ), array( 'status' => 409 ) );
	}

	$url = sprintf(
		'%s/api/v1/server/sites/%s/reports/%s',
		$settings['service_url'],
		rawurlencode( $settings['public_key'] ),
		rawurlencode( $name )
	);
	if ( $params ) {
		$url .= '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	// The key and the cache generation are part of the cache key: a new key never reads the
	// old one's answers, and a flush is a new generation (old entries expire on their own).
	$cache = 'analytics_connector_r_' . md5( $url . '|' . hash( 'sha256', $key ) . '|' . (int) get_option( 'analytics_connector_cache_generation', 0 ) );
	$hit   = get_transient( $cache );
	if ( is_array( $hit ) && isset( $hit['error'] ) ) {
		return new WP_Error( $hit['error'][0], $hit['error'][1], array( 'status' => $hit['error'][2] ) );
	}
	if ( is_array( $hit ) ) {
		return $hit;
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
			),
			'user-agent'  => 'Analytics for WordPress/' . ANALYTICS_CONNECTOR_VERSION . '; ' . home_url( '/' ),
		)
	);

	$error = analytics_connector_response_error( $response );
	if ( $error ) {
		// Remembered for half a minute: a service that is down does not slow every admin page
		// that asks (the dashboard widget) by the whole timeout.
		set_transient( $cache, array( 'error' => array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data()['status'] ?? 502 ) ), 30 );
		return $error;
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || ! isset( $body['data'] ) ) {
		return new WP_Error( 'analytics_bad_response', __( 'The analytics service sent an answer the plugin could not read. Check the service address.', 'analytics-connector' ), array( 'status' => 502 ) );
	}

	set_transient( $cache, $body, 'realtime' === $name ? 10 : MINUTE_IN_SECONDS );

	return $body;
}

/**
 * The service's answer as an error in words, or null when it is a success.
 *
 * Only the status is interpreted: the service's own text never reaches the screen.
 *
 * @param array|WP_Error $response From wp_remote_get().
 */
function analytics_connector_response_error( $response ): ?WP_Error {
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'analytics_unreachable', __( 'The analytics service cannot be reached. Check the service address, or try again in a minute.', 'analytics-connector' ), array( 'status' => 502 ) );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( $status >= 200 && $status < 300 ) {
		return null;
	}

	$messages = array(
		401 => array( 'analytics_key_invalid', __( 'The service refused the API key: it may have been revoked or mistyped. Create a new one and paste it in Settings.', 'analytics-connector' ) ),
		403 => array( 'analytics_key_scope', __( 'The API key cannot read reports. Create one with the “Read reports” permission.', 'analytics-connector' ) ),
		404 => array( 'analytics_not_found', __( 'The service does not know this site with this key, or it is too old to send reports to WordPress. Check the public key; if it is right, the service needs updating.', 'analytics-connector' ) ),
		422 => array( 'analytics_bad_request', __( 'The service did not accept the period asked for.', 'analytics-connector' ) ),
		429 => array( 'analytics_rate_limited', __( 'Too many requests to the service. Try again in a minute.', 'analytics-connector' ) ),
	);
	[ $code, $message ] = $messages[ $status ] ?? array( 'analytics_service_error', __( 'The analytics service had a problem. Try again in a minute.', 'analytics-connector' ) );

	// WordPress's own answer statuses: 401 and 403 would read, in the browser, as "you are not
	// logged in to WordPress". A key or site the service refuses is a conflict of the settings.
	$wp_status = match ( $status ) {
		401, 403, 404 => 409,
		422, 429      => $status,
		default       => 502,
	};

	return new WP_Error( $code, $message, array( 'status' => $wp_status ) );
}

/**
 * Drops every cached report: a new generation, so no cached answer is found any more.
 * Race-free, unlike a list of cached keys that parallel requests would each rewrite.
 */
function analytics_connector_flush_reports(): void {
	update_option( 'analytics_connector_cache_generation', (int) get_option( 'analytics_connector_cache_generation', 0 ) + 1, false );
}
