<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Kernel\ContainerFactory;
use Analytics\Kernel\Settings;
use Analytics\Shared\Mail\Mailer;
use Analytics\Shared\Mail\RecordingMailer;
use Analytics\Shared\RateLimit\RateLimiter;
use DI\Container;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Builds the real container against the per-worker test database with deterministic services.
 */
final class TestContainer
{
    public const string NOW = '2026-09-17 10:00:00';

    private static ?Container $container = null;

    public static function get(): Container
    {
        return self::$container ??= self::build();
    }

    private static ?ResettableRateLimitStorage $storage = null;

    public static function rateLimitStorage(): ResettableRateLimitStorage
    {
        return self::$storage ??= new ResettableRateLimitStorage();
    }

    public static function reset(): void
    {
        self::$container = null;
    }

    /** @param array<string, mixed> $env */
    public static function build(array $env = [], string $dbSuffix = '', array $overrides = []): Container
    {
        TestDatabase::prepare();
        $settings = TestDatabase::settings($env, $dbSuffix);

        return ContainerFactory::create($settings, $overrides + [
            ClockInterface::class => new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')), 'UTC'),
            Mailer::class => new RecordingMailer(true),
            AdapterInterface::class => new ArrayAdapter(0, false),
            RateLimiter::class => new RateLimiter(self::rateLimitStorage()),
        ]);
    }

    public static function settings(): Settings
    {
        $settings = self::get()->get(Settings::class);
        \assert($settings instanceof Settings);

        return $settings;
    }
}
