<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The API key stored in the option: encrypted, readable back, never in clear. A process per
 * test, because other tests define ANALYTICS_CONNECTOR_API_KEY, which wins over the option.
 */
#[RunTestsInSeparateProcesses]
final class SecretsTest extends TestCase
{
    private const API_KEY = 'ak_1a2B3c4D_abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    private string $salt = 'first-salt';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_salt')->alias(fn (): string => $this->salt);
    }

    public function testTheKeyShape(): void
    {
        self::assertTrue(analytics_connector_is_valid_api_key(self::API_KEY));
        self::assertFalse(analytics_connector_is_valid_api_key(self::API_KEY . "\n"));
        self::assertFalse(analytics_connector_is_valid_api_key('ak_short_secret'));
        self::assertFalse(analytics_connector_is_valid_api_key(self::KEY));
        self::assertFalse(analytics_connector_is_valid_api_key(null));
    }

    public function testTheKeyIsStoredEncryptedAndReadBack(): void
    {
        self::assertSame('', analytics_connector_get_api_key());

        analytics_connector_set_api_key(self::API_KEY);

        $stored = $this->options['analytics_connector_api_key'];
        self::assertIsString($stored);
        self::assertStringNotContainsString('abcdefghijklmnop', $stored);
        self::assertStringNotContainsString('abcdefghijklmnop', (string) base64_decode($stored, true));
        self::assertSame(self::API_KEY, analytics_connector_get_api_key());
        self::assertSame('ak_1a2B3c4D_…', analytics_connector_api_key_hint());
        self::assertFalse(analytics_connector_api_key_from_config());
    }

    public function testNewSaltsMeanTheKeyHasToBePastedAgain(): void
    {
        analytics_connector_set_api_key(self::API_KEY);
        $this->salt = 'second-salt';

        self::assertSame('', analytics_connector_get_api_key());
        self::assertSame('', analytics_connector_api_key_hint());
    }

    public function testRemovingTheKeyDropsTheCachedReports(): void
    {
        analytics_connector_set_api_key(self::API_KEY);
        $generation = $this->options['analytics_connector_cache_generation'];

        analytics_connector_set_api_key('');

        self::assertArrayNotHasKey('analytics_connector_api_key', $this->options);
        self::assertSame($generation + 1, $this->options['analytics_connector_cache_generation']);
        self::assertSame('', analytics_connector_get_api_key());
    }

    public function testTheConstantWins(): void
    {
        analytics_connector_set_api_key(self::API_KEY);
        \define('ANALYTICS_CONNECTOR_API_KEY', ' ak_fromConf_secret ');

        self::assertTrue(analytics_connector_api_key_from_config());
        self::assertSame('ak_fromConf_secret', analytics_connector_get_api_key());
    }
}
