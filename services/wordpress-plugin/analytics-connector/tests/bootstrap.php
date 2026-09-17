<?php
/**
 * PHPUnit bootstrap: Composer autoloader, WordPress constants/classes and the plugin function files.
 *
 * WordPress functions are mocked per test with Brain Monkey.
 *
 * @package AnalyticsConnector
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
}

$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/includes/settings.php';
require_once $plugin_dir . '/includes/frontend.php';
require_once $plugin_dir . '/includes/conversions.php';
