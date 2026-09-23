<?php
/**
 * The API key: the secret that lets WordPress read the site's reports from the service.
 *
 * Where it comes from, first match wins:
 * 1. `define( 'ANALYTICS_CONNECTOR_API_KEY', 'ak_…' );` in wp-config.php — never in the database;
 * 2. the key pasted in Analytics → Settings, stored encrypted (sodium secretbox) in the
 *    `analytics_connector_api_key` option, with a key derived from the site's salts. A database
 *    dump alone does not give the key away; the files and the database together do, as they
 *    do for every WordPress secret.
 *
 * The key never reaches the browser: the settings screen shows its prefix (`ak_1a2b3c4d_…`).
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/** The shape of a key: "ak_", 8 characters of prefix, "_", the secret. */
function analytics_connector_is_valid_api_key( $key ): bool {
	return is_string( $key ) && 1 === preg_match( '/^ak_[A-Za-z0-9]{8}_[A-Za-z0-9_-]{43}$/D', $key );
}

/** True when wp-config.php sets the key: the settings screen then cannot change it. */
function analytics_connector_api_key_from_config(): bool {
	return defined( 'ANALYTICS_CONNECTOR_API_KEY' ) && '' !== (string) constant( 'ANALYTICS_CONNECTOR_API_KEY' );
}

/** The 32-byte key the option is encrypted with, from the salts in wp-config.php. */
function analytics_connector_secret_box_key(): string {
	$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
	if ( '' === $salt ) {
		$salt = wp_salt( 'auth' );
	}

	return hash_hmac( 'sha256', 'analytics-connector/api-key', $salt, true );
}

/** The API key, or '' when there is none (or the stored one cannot be decrypted any more). */
function analytics_connector_get_api_key(): string {
	if ( analytics_connector_api_key_from_config() ) {
		return trim( (string) constant( 'ANALYTICS_CONNECTOR_API_KEY' ) );
	}

	$stored = get_option( 'analytics_connector_api_key', '' );
	if ( ! is_string( $stored ) || '' === $stored || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
		return '';
	}

	$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- our own ciphertext.
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}

	$plain = sodium_crypto_secretbox_open(
		substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
		analytics_connector_secret_box_key()
	);

	// The salts changed (a new wp-config.php): the key has to be pasted again.
	return false === $plain ? '' : $plain;
}

/** Stores the key encrypted; '' removes it. */
function analytics_connector_set_api_key( string $key ): void {
	if ( '' === $key ) {
		delete_option( 'analytics_connector_api_key' );
		analytics_connector_flush_reports();
		return;
	}

	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$box   = sodium_crypto_secretbox( $key, $nonce, analytics_connector_secret_box_key() );
	update_option( 'analytics_connector_api_key', base64_encode( $nonce . $box ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext in an option.
	analytics_connector_flush_reports();
}

/** What the screen may show of the key: its public prefix, "ak_1a2b3c4d_…", or ''. */
function analytics_connector_api_key_hint(): string {
	$key = analytics_connector_get_api_key();

	return '' === $key ? '' : substr( $key, 0, 12 ) . '…';
}
