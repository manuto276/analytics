<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected const KEY = 'pk_AbCdEfGhIjKlMnOpQrStU';

    /** @var array<string, mixed> */
    protected array $options = [];

    /** @var array<string, mixed> */
    protected array $transients = [];

    /** False in the test that loads the plugin file, which defines the constants itself. */
    protected bool $defineConstants = true;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if ($this->defineConstants && !\defined('ANALYTICS_CONNECTOR_VERSION')) {
            \define('ANALYTICS_CONNECTOR_VERSION', '1.0.0');
            \define('ANALYTICS_CONNECTOR_FILE', \dirname(__DIR__, 2) . '/analytics-connector.php');
            \define('ANALYTICS_CONNECTOR_DIR', \dirname(__DIR__, 2) . '/');
        }
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->options[$name] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $name): bool {
            unset($this->options[$name]);

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $name): mixed => $this->transients[$name] ?? false);
        Functions\when('set_transient')->alias(function (string $name, mixed $value): bool {
            $this->transients[$name] = $value;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $name): bool {
            unset($this->transients[$name]);

            return true;
        });
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://www.example.com' . $path);
        Functions\when('wp_unslash')->returnArg();
        Functions\when('shortcode_atts')->alias(static function (array $pairs, array $atts): array {
            $out = [];
            foreach ($pairs as $name => $default) {
                $out[$name] = $atts[$name] ?? $default;
            }

            return $out;
        });
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $settings
     */
    protected function configure(array $settings = []): void
    {
        $this->options['analytics_connector_settings'] = array_merge(
            ['service_url' => 'https://stats.example.net', 'public_key' => self::KEY],
            $settings,
        );
    }
}
