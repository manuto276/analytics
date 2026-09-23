<?php
/**
 * The admin screens.
 *
 *   Analytics
 *     Overview   the site's statistics, read from the service with the API key   analytics_view
 *     Settings   the connection, the key, who is not counted, the checks       analytics_manage
 *
 * Each item is a WordPress page holding the same React app (`build/admin.js`, source in
 * `src/`), told which view to show by `data-view` — the same shape as the workspace's other
 * plugins. And a widget on the WordPress dashboard: visitors today and in the last 7 days.
 *
 * @package AnalyticsConnector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page slug => [view, capability].
 *
 * @return array<string, array{0: string, 1: string}>
 */
function analytics_connector_admin_views(): array {
	return array(
		'analytics'          => array( 'overview', 'analytics_view' ),
		'analytics-settings' => array( 'settings', 'analytics_manage' ),
	);
}

/** The menu: Analytics → Overview, Settings. */
function analytics_connector_admin_menu(): void {
	$labels = array(
		'analytics'          => __( 'Overview', 'analytics-connector' ),
		'analytics-settings' => __( 'Settings', 'analytics-connector' ),
	);
	$svg    = (string) file_get_contents( ANALYTICS_CONNECTOR_DIR . 'assets/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own icon.
	// An administrator without analytics_view (capabilities not installed yet) still finds Settings.
	$top    = current_user_can( 'analytics_view' ) ? 'analytics_view' : 'analytics_manage';
	$hooks  = array( add_menu_page( __( 'Analytics', 'analytics-connector' ), __( 'Analytics', 'analytics-connector' ), $top, 'analytics', 'analytics_connector_render_app', 'data:image/svg+xml;base64,' . base64_encode( $svg ), 3 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the menu icon as a data URL, as WordPress documents.

	foreach ( analytics_connector_admin_views() as $slug => [ $view, $cap ] ) {
		$hooks[] = add_submenu_page( 'analytics', $labels[ $slug ], $labels[ $slug ], $cap, $slug, 'analytics_connector_render_app' );
	}
	foreach ( array_filter( $hooks ) as $hook ) {
		add_action( "load-$hook", 'analytics_connector_admin_assets' );
	}
}

/** The page: the app's mount point, with the view to show. */
function analytics_connector_render_app(): void {
	$page  = sanitize_key( wp_unslash( (string) ( $_GET['page'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which menu page is open; nothing is changed.
	$views = analytics_connector_admin_views();
	printf( '<div class="wrap anc-wrap"><div id="anc-app" data-view="%s"></div></div>', esc_attr( $views[ $page ][0] ?? 'overview' ) );
}

/** The app's script, style, translations and configuration, on its pages only. */
function analytics_connector_admin_assets(): void {
	add_action( 'admin_enqueue_scripts', 'analytics_connector_enqueue_admin_app' );
	add_filter( 'admin_body_class', static fn ( string $classes ): string => "$classes anc-screen" );
}

/** Enqueues build/admin.js and passes it `window.analyticsConnectorAdmin`. */
function analytics_connector_enqueue_admin_app(): void {
	$asset = ANALYTICS_CONNECTOR_DIR . 'build/admin.asset.php';
	if ( ! is_file( $asset ) ) {
		return;
	}
	$meta = require $asset;
	$url  = plugin_dir_url( ANALYTICS_CONNECTOR_FILE ) . 'build/';
	wp_enqueue_script( 'analytics-connector-admin', $url . 'admin.js', $meta['dependencies'], $meta['version'], true );
	wp_enqueue_style( 'analytics-connector-admin', $url . 'admin.css', array( 'wp-components' ), $meta['version'] );
	wp_set_script_translations( 'analytics-connector-admin', 'analytics-connector', ANALYTICS_CONNECTOR_DIR . 'languages' );

	$pages = array();
	foreach ( analytics_connector_admin_views() as $slug => [ $view, $cap ] ) {
		if ( current_user_can( $cap ) ) {
			$pages[ $view ] = admin_url( "admin.php?page=$slug" );
		}
	}
	$settings = analytics_connector_get_settings();

	wp_add_inline_script(
		'analytics-connector-admin',
		'window.analyticsConnectorAdmin = ' . wp_json_encode(
			array(
				'site'      => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'version'   => ANALYTICS_CONNECTOR_VERSION,
				'can'       => array(
					'manage' => current_user_can( 'analytics_manage' ),
					'view'   => current_user_can( 'analytics_view' ),
				),
				'pages'     => $pages,
				'icon'      => plugin_dir_url( ANALYTICS_CONNECTOR_FILE ) . 'assets/icon.svg',
				// Whether there is a key, never the key.
				'connected' => '' !== $settings['service_url'] && analytics_connector_is_valid_public_key( $settings['public_key'] ),
				'hasKey'    => '' !== analytics_connector_get_api_key(),
				'service'   => $settings['service_url'],
			)
		) . ';',
		'before'
	);
}

/**
 * "Settings" next to "Deactivate" in the plugin list.
 *
 * @param array<int|string, string> $links The row's links.
 */
function analytics_connector_action_links( array $links ): array {
	if ( current_user_can( 'analytics_manage' ) ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=analytics-settings' ) ), esc_html__( 'Settings', 'analytics-connector' ) ) );
	}

	return $links;
}

/** The widget on the WordPress dashboard, for whoever may see the numbers once there is a key. */
function analytics_connector_dashboard_widget(): void {
	if ( ! current_user_can( 'analytics_view' ) || '' === analytics_connector_get_api_key() ) {
		return;
	}
	wp_add_dashboard_widget( 'analytics_connector', __( 'Analytics', 'analytics-connector' ), 'analytics_connector_render_dashboard_widget' );
}

/** Visitors today and in the last 7 days, and a link to the Overview. Server-rendered: two cached calls. */
function analytics_connector_render_dashboard_widget(): void {
	$today = analytics_connector_report( 'overview', array( 'period' => 'today' ) );
	$week  = is_wp_error( $today ) ? $today : analytics_connector_report( 'overview', array( 'period' => '7d' ) );

	echo '<div class="anc-widget">';
	if ( is_wp_error( $week ) ) {
		printf( '<p>%s</p>', esc_html( $week->get_error_message() ) );
	} else {
		$cells = array(
			array( __( 'Visitors today', 'analytics-connector' ), $today['data']['metrics']['visitors'] ?? null ),
			array( __( 'Visitors, last 7 days', 'analytics-connector' ), $week['data']['metrics']['visitors'] ?? null ),
			array( __( 'Pageviews, last 7 days', 'analytics-connector' ), $week['data']['metrics']['pageviews'] ?? null ),
		);
		echo '<ul style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:0 0 12px">';
		foreach ( $cells as [ $label, $value ] ) {
			printf(
				'<li style="margin:0"><span style="display:block;font-size:24px;font-weight:600;line-height:1.3">%s</span><span style="color:#646970">%s</span></li>',
				esc_html( null === $value ? '—' : number_format_i18n( (int) $value ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
	printf( '<p style="margin:0"><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=analytics' ) ), esc_html__( 'All the statistics', 'analytics-connector' ) );
	echo '</div>';
}
