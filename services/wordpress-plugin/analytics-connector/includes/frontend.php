<?php
/**
 * Front end: tracker script, content key meta tag and consent links.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracker script URL, or null when the plugin is not configured.
 *
 * @param array<string, string> $settings Plugin settings.
 */
function analytics_connector_script_url( array $settings ): ?string {
	if ( ! analytics_connector_is_valid_public_key( $settings['public_key'] ) ) {
		return null;
	}

	if ( 'proxy' === $settings['mode'] ) {
		return '' === $settings['proxy_path'] ? null : home_url( $settings['proxy_path'] . $settings['public_key'] . '.js' );
	}

	return '' === $settings['service_url'] ? null : $settings['service_url'] . '/t/' . $settings['public_key'] . '.js';
}

/** Enqueues the deferred tracker in the head with the queue stub before it. */
function analytics_connector_enqueue_scripts(): void {
	$settings = analytics_connector_get_settings();
	$src      = analytics_connector_script_url( $settings );
	$cap      = $settings['skip_capability'];

	if ( null === $src || ( '' !== $cap && is_user_logged_in() && current_user_can( $cap ) ) ) {
		return;
	}

	$global = preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $settings['global_name'] ) ? $settings['global_name'] : 'analytics';

	wp_enqueue_script(
		'analytics-connector',
		$src,
		array(),
		null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the service versions the script (ETag).
		array(
			'strategy'  => 'defer',
			'in_footer' => false,
		)
	);
	wp_add_inline_script(
		'analytics-connector',
		sprintf( "window.%1\$s=window.%1\$s||{q:[],track(){this.q.push(['track',...arguments])}}", $global ),
		'before'
	);
}

/** Prints `<meta name="analytics:content">` when the content key filter returns a value. */
function analytics_connector_content_meta(): void {
	/**
	 * Filters the content key of the current page (for example "author:42"). Null or empty prints nothing.
	 *
	 * @param string|null $content_key Content key.
	 */
	$key = apply_filters( 'analytics_connector_content_key', null );

	if ( is_scalar( $key ) && '' !== (string) $key ) {
		printf( '<meta name="analytics:content" content="%s">' . "\n", esc_attr( (string) $key ) );
	}
}

/** Registers the shortcode and the consent link block. */
function analytics_connector_init(): void {
	add_shortcode( 'analytics_consent_link', 'analytics_connector_consent_link_shortcode' );
	register_block_type( dirname( __DIR__ ) . '/blocks/consent-link', array( 'render_callback' => 'analytics_connector_render_consent_block' ) );
}

/**
 * `[analytics_consent_link label="…"]`: a link that reopens the consent preferences.
 *
 * @param array<string, string>|string $atts Shortcode attributes.
 */
function analytics_connector_consent_link_shortcode( $atts = array() ): string {
	$atts  = shortcode_atts( array( 'label' => '' ), is_array( $atts ) ? $atts : array(), 'analytics_consent_link' );
	$label = '' !== trim( (string) $atts['label'] ) ? (string) $atts['label'] : __( 'Cookie settings', 'analytics-connector' );

	return sprintf( '<a href="#analytics-consent" data-analytics-consent>%s</a>', esc_html( $label ) );
}

/**
 * Server-side render of the `analytics-connector/consent-link` block.
 *
 * @param array<string, mixed> $attributes Block attributes.
 */
function analytics_connector_render_consent_block( $attributes ): string {
	return analytics_connector_consent_link_shortcode( array( 'label' => (string) ( $attributes['label'] ?? '' ) ) );
}

/**
 * Marks menu links pointing to `#analytics-consent` as consent links.
 *
 * @param array<string, mixed> $atts Link attributes.
 */
function analytics_connector_menu_link_attributes( $atts ) {
	if ( is_array( $atts ) && isset( $atts['href'] ) && str_ends_with( (string) $atts['href'], '#analytics-consent' ) ) {
		$atts['data-analytics-consent'] = 'true'; // Empty values are dropped by the menu walker.
	}

	return $atts;
}
