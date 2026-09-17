<?php
/**
 * Settings: option storage, sanitisation and the Settings → Analytics page.
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

/** Registers the option with the Settings API (options.php checks the nonce and capability). */
function analytics_connector_register_setting(): void {
	register_setting(
		'analytics_connector',
		'analytics_connector_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'analytics_connector_sanitize_settings',
			'default'           => analytics_connector_default_settings(),
		)
	);
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
		add_settings_error(
			'analytics_connector_settings',
			'invalid_public_key',
			__( 'The public key must be "pk_" followed by 21 letters or digits. The previous key was kept.', 'analytics-connector' )
		);
	}

	$output['mode'] = 'proxy' === ( $input['mode'] ?? '' ) ? 'proxy' : 'direct';
	$path                 = trim( (string) preg_replace( array( '#[^A-Za-z0-9/_-]#', '#/+#' ), array( '', '/' ), (string) ( $input['proxy_path'] ?? '' ) ), '/' );
	$output['proxy_path'] = '' === $path ? '' : '/' . $path . '/';
	$output['skip_capability'] = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $input['skip_capability'] ?? '' ) ) );
	$global                = trim( (string) ( $input['global_name'] ?? '' ) );
	$output['global_name'] = preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/D', $global ) ? $global : 'analytics';

	return $output;
}

/** Adds Settings → Analytics. */
function analytics_connector_add_settings_page(): void {
	$title = __( 'Analytics', 'analytics-connector' );
	add_options_page( $title, $title, 'manage_options', 'analytics-connector', 'analytics_connector_render_settings_page' );
}

/** Renders the settings page. */
function analytics_connector_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = analytics_connector_get_settings();
	$fields   = array(
		'service_url'     => array( __( 'Service URL', 'analytics-connector' ), 'https://stats.example.net', __( 'Base URL of the analytics service, without trailing slash.', 'analytics-connector' ) ),
		'public_key'      => array( __( 'Public key', 'analytics-connector' ), 'pk_XXXXXXXXXXXXXXXXXXXXX', __( 'Site public key shown in the analytics dashboard.', 'analytics-connector' ) ),
		'proxy_path'      => array( __( 'Proxy path', 'analytics-connector' ), '/stats/', __( 'Used in proxy mode: the script loads from {path}{public key}.js on this site.', 'analytics-connector' ) ),
		'skip_capability' => array( __( 'Do not track users who can', 'analytics-connector' ), 'edit_posts', __( 'Logged-in users with this capability are not tracked. Leave empty to track everyone.', 'analytics-connector' ) ),
		'global_name'     => array( __( 'JavaScript global', 'analytics-connector' ), 'analytics', __( 'Name of the global API object (window.analytics by default).', 'analytics-connector' ) ),
	);
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php settings_fields( 'analytics_connector' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Load mode', 'analytics-connector' ); ?></th>
					<td><fieldset>
						<?php foreach ( array( 'direct' => __( 'Direct from the service URL', 'analytics-connector' ), 'proxy' => __( 'First-party proxy path', 'analytics-connector' ) ) as $mode => $label ) : ?>
							<label><input type="radio" name="analytics_connector_settings[mode]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $settings['mode'], $mode ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset></td>
				</tr>
				<?php foreach ( $fields as $name => $field ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( 'analytics-connector-' . $name ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="<?php echo esc_attr( 'analytics-connector-' . $name ); ?>" name="<?php echo esc_attr( 'analytics_connector_settings[' . $name . ']' ); ?>" value="<?php echo esc_attr( $settings[ $name ] ); ?>" placeholder="<?php echo esc_attr( $field[1] ); ?>">
							<p class="description"><?php echo esc_html( $field[2] ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
