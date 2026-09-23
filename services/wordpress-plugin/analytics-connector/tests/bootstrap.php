<?php
/**
 * PHPUnit bootstrap: Composer autoloader, WordPress constants/classes and the plugin function files.
 *
 * The plugin's own constants (ANALYTICS_CONNECTOR_VERSION…) are defined by TestCase, not here,
 * so PluginFileTest can load the plugin file, which defines them, in a process of its own.
 *
 * WordPress functions are mocked per test with Brain Monkey.
 *
 * @package AnalyticsConnector
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WP_REST_Request implements ArrayAccess {
		/**
		 * @param array<string, mixed> $url_params
		 * @param array<string, mixed> $query
		 * @param array<string, mixed> $json
		 */
		public function __construct( public array $url_params = array(), public array $query = array(), public array $json = array() ) {}

		public function get_json_params(): array {
			return $this->json;
		}

		public function get_query_params(): array {
			return $this->query;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->url_params[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->url_params[ $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->url_params[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->url_params[ $offset ] );
		}
	}
}

$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/includes/settings.php';
require_once $plugin_dir . '/includes/frontend.php';
require_once $plugin_dir . '/includes/conversions.php';
require_once $plugin_dir . '/includes/secrets.php';
require_once $plugin_dir . '/includes/client.php';
require_once $plugin_dir . '/includes/status.php';
require_once $plugin_dir . '/includes/rest-admin.php';
require_once $plugin_dir . '/includes/admin-app.php';
