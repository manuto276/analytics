<?php
/**
 * Server-side conversions API client.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends a conversion to the analytics service without waiting for the response.
 * Needs the service URL, the public key and an API key with the conversions:write scope — the
 * ANALYTICS_CONNECTOR_API_KEY constant, or the key saved in Analytics → Settings.
 *
 * @param string               $name Conversion name, e.g. "purchase".
 * @param array<string, mixed> $args id, occurred_at (timestamp|DateTimeInterface), visitor_id (default an_vid cookie),
 *                                   customer_ref, value {amount_minor, currency}, props.
 * @return bool False when not configured or the request could not be dispatched.
 */
function analytics_connector_track_conversion( string $name, array $args = array() ): bool {
	$settings = analytics_connector_get_settings();
	$api_key  = analytics_connector_get_api_key();

	if ( '' === $api_key || '' === $name || '' === $settings['service_url'] || ! analytics_connector_is_valid_public_key( $settings['public_key'] ) ) {
		return false;
	}

	$occurred = $args['occurred_at'] ?? time();
	$occurred = $occurred instanceof DateTimeInterface ? $occurred->getTimestamp() : (int) $occurred;
	$payload  = array(
		'id'          => isset( $args['id'] ) && '' !== (string) $args['id'] ? (string) $args['id'] : wp_generate_uuid4(),
		'name'        => $name,
		'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', $occurred ),
	);

	$visitor_id = $args['visitor_id'] ?? ( isset( $_COOKIE['an_vid'] ) ? wp_unslash( $_COOKIE['an_vid'] ) : null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
	if ( is_string( $visitor_id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/D', $visitor_id ) ) {
		$payload['visitor_id'] = $visitor_id;
	}
	if ( isset( $args['customer_ref'] ) && '' !== (string) $args['customer_ref'] ) {
		$payload['customer_ref'] = (string) $args['customer_ref'];
	}
	if ( isset( $args['value']['amount_minor'], $args['value']['currency'] ) ) {
		$payload['value'] = array(
			'amount_minor' => (int) $args['value']['amount_minor'],
			'currency'     => strtoupper( (string) $args['value']['currency'] ),
		);
	}
	if ( ! empty( $args['props'] ) && is_array( $args['props'] ) ) {
		$payload['props'] = $args['props'];
	}

	$response = wp_remote_post(
		$settings['service_url'] . '/api/v1/server/sites/' . rawurlencode( $settings['public_key'] ) . '/conversions',
		array(
			'blocking'    => false,
			'timeout'     => 2,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		)
	);

	return ! is_wp_error( $response );
}
