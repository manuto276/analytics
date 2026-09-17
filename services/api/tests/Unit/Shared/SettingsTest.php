<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Kernel\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function testProdRequiresSecrets(): void
    {
        try {
            Settings::fromEnvironment(['APP_ENV' => 'prod'], '/app');
            self::fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('APP_SECRET', $e->getMessage());
        }
    }

    public function testParsesEnvironment(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'prod',
            'APP_URL' => 'https://stats.example.net/',
            'APP_SECRET' => base64_encode(random_bytes(32)),
            'APP_ENCRYPTION_KEYS' => 'k1:' . base64_encode(random_bytes(32)) . ', k2:' . base64_encode(random_bytes(32)),
            'DB_HOST' => 'db', 'DB_NAME' => 'stats', 'DB_USER' => 'u', 'DB_PASSWORD' => 'p@ss',
            'TRUSTED_PROXIES' => '10.0.0.0/8, 172.16.0.0/12',
            'INGEST_MODE' => 'queue',
            'DB_PARTITIONING' => 'false',
            'APP_TEST_CLOCK' => '2020-01-01T00:00:00Z',
        ], '/app');
        self::assertSame('https://stats.example.net', $settings->appUrl);
        self::assertSame('https://stats.example.net', $settings->appOrigin());
        self::assertSame('stats.example.net', $settings->appHost());
        self::assertSame('mysql://u:p%40ss@db:3306/stats', $settings->databaseUrl);
        self::assertSame(['10.0.0.0/8', '172.16.0.0/12'], $settings->trustedProxies);
        self::assertSame(['k1', 'k2'], array_keys($settings->encryptionKeys));
        self::assertSame('queue', $settings->ingestMode);
        self::assertFalse($settings->dbPartitioning);
        self::assertNull($settings->testClock, 'test clock is only honoured in APP_ENV=test');
        self::assertTrue($settings->usesHttps());
    }

    public function testRejectsInvalidValues(): void
    {
        foreach ([['APP_ENV' => 'staging'], ['APP_ENV' => 'dev', 'INGEST_MODE' => 'kafka'], ['APP_ENV' => 'dev', 'APP_ENCRYPTION_KEYS' => 'BAD'], ['APP_ENV' => 'dev', 'APP_SECRET' => 'c2hvcnQ=']] as $env) {
            try {
                Settings::fromEnvironment($env, '/app');
                self::fail('expected failure for ' . json_encode($env));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
