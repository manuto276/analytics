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

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubEscapeFunctions();
        Functions\stubTranslationFunctions();
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->options[$name] ?? $default);
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
