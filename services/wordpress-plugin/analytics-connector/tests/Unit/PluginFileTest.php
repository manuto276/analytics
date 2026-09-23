<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** The plugin file: constants, includes and every hook, in a process of its own (it defines constants). */
final class PluginFileTest extends TestCase
{
    protected bool $defineConstants = false;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginFileRegistersHooks(): void
    {
        Functions\when('plugin_dir_path')->alias(static fn (string $file): string => \dirname($file) . '/');
        Functions\when('plugin_basename')->justReturn('analytics-connector/analytics-connector.php');
        Functions\expect('register_activation_hook')->once()->with(\Mockery::type('string'), 'analytics_connector_install_capabilities');

        Actions\expectAdded('init')->once()->with('analytics_connector_init');
        Actions\expectAdded('wp_enqueue_scripts')->once()->with('analytics_connector_enqueue_scripts');
        Actions\expectAdded('wp_head')->once()->with('analytics_connector_content_meta', 1);
        Filters\expectAdded('nav_menu_link_attributes')->once()->with('analytics_connector_menu_link_attributes');
        Actions\expectAdded('admin_init')->once()->with('analytics_connector_maybe_upgrade');
        Actions\expectAdded('admin_menu')->once()->with('analytics_connector_admin_menu');
        Actions\expectAdded('wp_dashboard_setup')->once()->with('analytics_connector_dashboard_widget');
        Actions\expectAdded('rest_api_init')->once()->with('analytics_connector_register_admin_routes');
        Filters\expectAdded('plugin_action_links_analytics-connector/analytics-connector.php')->once()->with('analytics_connector_action_links');

        require \dirname(__DIR__, 2) . '/analytics-connector.php';

        self::assertSame('1.0.0', ANALYTICS_CONNECTOR_VERSION);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheHeaderVersionMatchesTheConstant(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/analytics-connector.php');
        self::assertMatchesRegularExpression('/^ \* Version:\s+(\S+)$/m', $source);
        preg_match('/^ \* Version:\s+(\S+)$/m', $source, $header);
        preg_match("/define\\( 'ANALYTICS_CONNECTOR_VERSION', '([^']+)' \\)/", $source, $constant);

        self::assertSame($header[1], $constant[1]);
    }
}
