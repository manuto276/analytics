<?php

declare(strict_types=1);

namespace Analytics\Kernel;

use DI\Container;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Boot entry used by public/index.php and bin/analytics.
 */
final class Kernel
{
    public static function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public static function settings(?string $projectDir = null): Settings
    {
        $projectDir ??= self::projectDir();
        if (is_file($projectDir . '/.env')) {
            new Dotenv()->usePutenv(false)->load($projectDir . '/.env');
        }
        /** @var array<string, mixed> $env */
        $env = $_ENV + $_SERVER;

        return Settings::fromEnvironment($env, $projectDir);
    }

    public static function container(?Settings $settings = null): Container
    {
        return ContainerFactory::create($settings ?? self::settings());
    }
}
