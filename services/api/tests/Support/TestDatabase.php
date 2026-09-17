<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Kernel\ConsoleApplicationFactory;
use Analytics\Kernel\ContainerFactory;
use Analytics\Kernel\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * One database per ParaTest worker (analytics_test_{TEST_TOKEN}), migrated once per process.
 */
final class TestDatabase
{
    private static bool $prepared = false;

    public static function baseUrl(): string
    {
        $url = getenv('TEST_DATABASE_URL');

        return \is_string($url) && $url !== '' ? $url : 'mysql://root:root@127.0.0.1:33061/analytics_test';
    }

    public static function name(string $suffix = ''): string
    {
        $token = getenv('TEST_TOKEN');
        $parsed = parse_url(self::baseUrl());
        $base = ltrim($parsed['path'] ?? '/analytics_test', '/');

        return $base . (\is_string($token) && $token !== '' ? '_' . $token : '') . $suffix;
    }

    public static function url(string $suffix = ''): string
    {
        return (string) preg_replace('#/[^/?]*(\?|$)#', '/' . self::name($suffix) . '$1', self::baseUrl(), 1);
    }

    public static function settings(array $env = [], string $suffix = ''): Settings
    {
        return Settings::fromEnvironment($env + [
            'APP_ENV' => 'test',
            'APP_URL' => 'https://analytics.test',
            'DATABASE_URL' => self::url($suffix),
            'TRUSTED_PROXIES' => '10.0.0.0/8',
            'LOG_DIR' => sys_get_temp_dir() . '/analytics-test-logs-' . (getenv('TEST_TOKEN') ?: '0'),
            'CACHE_DIR' => sys_get_temp_dir() . '/analytics-test-cache-' . (getenv('TEST_TOKEN') ?: '0'),
            'STORAGE_DIR' => sys_get_temp_dir() . '/analytics-test-storage-' . (getenv('TEST_TOKEN') ?: '0'),
        ], \dirname(__DIR__, 2));
    }

    public static function serverConnection(): Connection
    {
        $params = new DsnParser(['mysql' => 'pdo_mysql'])->parse(self::baseUrl());
        unset($params['dbname']);

        return DriverManager::getConnection($params);
    }

    public static function prepare(): void
    {
        if (self::$prepared) {
            return;
        }
        self::$prepared = true;
        self::recreate('');
    }

    public static function recreate(string $suffix, bool $migrate = true, array $env = []): Settings
    {
        $server = self::serverConnection();
        $name = self::name($suffix);
        $server->executeStatement('DROP DATABASE IF EXISTS `' . $name . '`');
        $server->executeStatement('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $server->close();

        $settings = self::settings($env, $suffix);
        if ($migrate) {
            self::migrate($settings);
        }

        return $settings;
    }

    public static function drop(string $suffix): void
    {
        $server = self::serverConnection();
        $server->executeStatement('DROP DATABASE IF EXISTS `' . self::name($suffix) . '`');
        $server->close();
    }

    public static function migrate(Settings $settings): void
    {
        $container = ContainerFactory::create($settings);
        $factory = $container->get(DependencyFactory::class);
        \assert($factory instanceof DependencyFactory);
        $application = ConsoleApplicationFactory::create($container);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => 'migrations:migrate', '--no-interaction' => true, '--allow-no-migration' => true]), $output);
        if ($code !== 0) {
            throw new \RuntimeException('Test migrations failed: ' . $output->fetch());
        }
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);
        $connection->close();
    }
}
