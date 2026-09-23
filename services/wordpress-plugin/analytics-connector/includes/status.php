<?php
/**
 * The checks the Settings screen shows: is the tracker served, what does the service say about
 * this site, does the API key work.
 *
 * The tracker script carries the site's configuration (`window.__an_cfg={…};`, see the service's
 * docs/architecture/tracker.md): reading it tells WordPress the cookie level, whether a consent
 * banner is published and which automatic events are on — without a key.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * The site's configuration from a tracker script, or null when the script carries none.
 *
 * @return array<string, mixed>|null
 */
function analytics_connector_parse_tracker_config( string $script ): ?array {
	if ( ! preg_match( '/window\.__an_cfg=(\{.*?\});\n/s', $script, $m ) ) {
		return null;
	}
	$cfg = json_decode( $m[1], true );

	return is_array( $cfg ) ? $cfg : null;
}

/**
 * The tracker's check: served or not, and what its configuration says.
 *
 * @return array<string, mixed>
 */
function analytics_connector_tracker_status(): array {
	$settings = analytics_connector_get_settings();
	$src      = analytics_connector_script_url( $settings );
	if ( null === $src ) {
		return array( 'state' => 'not_configured' );
	}

	$response = wp_remote_get(
		$src,
		array(
			'timeout'     => 8,
			'redirection' => 2,
			// The service answers the tracker only for the site's own pages.
			'headers'     => array( 'Referer' => home_url( '/' ) ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array(
			'state' => 'unreachable',
			'url'   => $src,
		);
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = (string) wp_remote_retrieve_body( $response );
	$cfg    = 200 === $status ? analytics_connector_parse_tracker_config( $body ) : null;
	if ( null === $cfg ) {
		return array(
			'state'  => 'error',
			'url'    => $src,
			'status' => $status,
		);
	}

	$consent = is_array( $cfg['consent'] ?? null ) ? $cfg['consent'] : null;

	return array(
		'state'     => 'ok',
		'url'       => $src,
		'bytes'     => strlen( $body ),
		'cookies'   => ! empty( $cfg['c'] ),
		'banner'    => null !== $consent,
		'languages' => $consent ? array_values( array_map( 'strval', array_keys( (array) ( $consent['texts'] ?? array() ) ) ) ) : array(),
		'version'   => $consent ? (int) ( $consent['v'] ?? 0 ) : 0,
		'auto'      => array(
			'outbound'  => ! empty( $cfg['auto']['outbound'] ),
			'downloads' => ! empty( $cfg['auto']['downloads'] ),
			'forms'     => ! empty( $cfg['auto']['forms'] ),
		),
	);
}

/**
 * The API key's check: none, from where, and whether the service lets it read reports.
 *
 * @return array<string, mixed>
 */
function analytics_connector_key_status(): array {
	$hint = analytics_connector_api_key_hint();
	if ( '' === $hint ) {
		return array( 'state' => 'none' );
	}

	$base = array(
		'hint'   => $hint,
		'source' => analytics_connector_api_key_from_config() ? 'config' : 'option',
	);
	$test = analytics_connector_report( 'overview', array( 'period' => 'today' ) );
	if ( is_wp_error( $test ) ) {
		return $base + array(
			'state'   => $test->get_error_code(),
			'message' => $test->get_error_message(),
		);
	}

	return $base + array( 'state' => 'ok' );
}

/**
 * Everything the Settings screen's "The site on the service" shows.
 *
 * @return array<string, mixed>
 */
function analytics_connector_status(): array {
	$settings = analytics_connector_get_settings();

	return array(
		'version' => ANALYTICS_CONNECTOR_VERSION,
		'tracker' => analytics_connector_tracker_status(),
		'key'     => analytics_connector_key_status(),
		'service' => '' === $settings['service_url'] ? '' : $settings['service_url'],
	);
}
