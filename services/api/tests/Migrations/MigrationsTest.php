<?php

declare(strict_types=1);

namespace Analytics\Tests\Migrations;

use Analytics\Kernel\Console\OrmValidateSchemaCommand;
use Analytics\Kernel\ContainerFactory;
use Analytics\Kernel\Settings;
use Analytics\Retention\Application\PartitionManager;
use Analytics\Shared\Doctrine\Partitioning;
use Analytics\Shared\Doctrine\SchemaAssets;
use Analytics\Tests\Support\TestContainer;
use Analytics\Tests\Support\TestDatabase;
use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * The schema is created only by migrations: a fresh database must end up exactly like the mapping,
 * with the expected partitions, and migrations must stay forward-only and expand-only.
 */
final class MigrationsTest extends TestCase
{
    private const string SUFFIX = '_mig';

    private static ?Container $container = null;

    public static function setUpBeforeClass(): void
    {
        $settings = TestDatabase::recreate(self::SUFFIX);
        self::$container = ContainerFactory::create($settings, [ClockInterface::class => new MockClock(new \DateTimeImmutable(TestContainer::NOW, new \DateTimeZone('UTC')), 'UTC')]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$container = null;
        TestDatabase::drop(self::SUFFIX);
    }

    private function container(): Container
    {
        self::assertNotNull(self::$container);

        return self::$container;
    }

    private function connection(): Connection
    {
        $connection = $this->container()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    public function testFreshDatabaseHasEveryTableAndNoPendingMigration(): void
    {
        $tables = $this->connection()->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
        $expected = [
            'sites', 'site_domains', 'users', 'user_site_roles', 'invitations', 'auth_sessions', 'totp_credentials',
            'recovery_codes', 'password_resets', 'email_changes', 'api_keys', 'goals', 'funnels', 'funnel_steps', 'campaign_costs',
            'audit_log', 'consent_configs', 'doctrine_migration_versions',
            ...SchemaAssets::DBAL_TABLES,
        ];
        foreach (array_unique($expected) as $table) {
            self::assertContains($table, $tables, 'Missing table ' . $table);
        }

        $factory = $this->container()->get(DependencyFactory::class);
        \assert($factory instanceof DependencyFactory);
        self::assertSame([], $factory->getMigrationStatusCalculator()->getNewMigrations()->getItems(), 'A fresh database must be fully migrated.');
        self::assertGreaterThanOrEqual(2, \count($factory->getMigrationRepository()->getMigrations()->getItems()));
    }

    public function testOrmMappingMatchesTheMigratedSchema(): void
    {
        $em = $this->container()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::assertSame([], OrmValidateSchemaCommand::pendingSql($em), 'Migrations and ORM mapping drifted apart.');
    }

    public function testPartitionsCoverTheCurrentMonthAndCanBeExtended(): void
    {
        $partitions = new PartitionManager($this->connection(), $this->settings());
        self::assertTrue($partitions->enabled());
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            $names = array_keys($partitions->partitions($table));
            self::assertContains('p_old', $names, $table);
            self::assertContains('pmax', $names, $table);
            self::assertContains(Partitioning::partitionName($today), $names, $table);
        }

        $created = $partitions->maintain($today->modify('+2 months'), 3);
        self::assertNotSame([], $created['events_raw'], 'maintain must add the missing months');
        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            $names = array_keys($partitions->partitions($table));
            for ($i = 0; $i <= 5; ++$i) {
                self::assertContains(Partitioning::partitionName($today->modify('+' . $i . ' months')), $names, $table);
            }
        }
        self::assertSame([], $partitions->maintain($today, 3)['events_raw'], 'maintain is idempotent');
    }

    public function testDatabaseWithoutPartitioningWorks(): void
    {
        $settings = TestDatabase::recreate('_nopart', true, ['DB_PARTITIONING' => 'false']);
        $container = ContainerFactory::create($settings);
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);
        try {
            $partitions = new PartitionManager($connection, $settings);
            self::assertFalse($partitions->enabled());
            foreach (Partitioning::PARTITIONED_TABLES as $table) {
                self::assertSame([], $partitions->partitions($table), $table . ' must not be partitioned');
            }
            $connection->executeStatement("INSERT INTO events_raw (local_day, site_id, event_uid, occurred_at, received_at, level, type, host, path, page_hash, channel) VALUES ('2020-01-01', 1, UNHEX('000102030405060708090a0b'), '2020-01-01 00:00:00.000', '2020-01-01 00:00:00.000', 'b', 'pv', 'example.com', '/', UNHEX('0011223344556677'), 'direct')");
            self::assertSame(1, $partitions->deleteBefore('events_raw', 'local_day', new \DateTimeImmutable('2021-01-01')));
        } finally {
            $connection->close();
            TestDatabase::drop('_nopart');
        }
    }

    public function testMigrationsAreForwardOnlyAndExpandOnly(): void
    {
        $forbidden = [
            '/\bDROP\s+COLUMN\b/i' => 'DROP COLUMN',
            '/\bRENAME\s+(COLUMN|TABLE|TO)\b/i' => 'RENAME',
            '/\bDROP\s+TABLE\b/i' => 'DROP TABLE',
            '/\bMODIFY\s+\w+\s+[^,)]*NOT\s+NULL(?![^,)]*DEFAULT)/i' => 'MODIFY … NOT NULL without DEFAULT',
        ];
        $files = glob(\dirname(__DIR__, 2) . '/migrations/Version*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringContainsString('IrreversibleMigration', $source, basename($file) . ' must refuse down migrations.');
            foreach (explode("\n", $source) as $number => $line) {
                if (str_contains($line, 'contract-ok')) {
                    continue;
                }
                foreach ($forbidden as $pattern => $label) {
                    self::assertSame(0, preg_match($pattern, $line), \sprintf('%s:%d uses %s; releases must stay compatible with the previous one (mark the line with "contract-ok" when it is safe).', basename($file), $number + 1, $label));
                }
            }
        }
    }

    private function settings(): Settings
    {
        $settings = $this->container()->get(Settings::class);
        \assert($settings instanceof Settings);

        return $settings;
    }
}
