<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use DI\Container;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Real MySQL; every test runs inside a transaction that is rolled back afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Container $container;
    protected Connection $db;
    protected EntityManagerInterface $em;
    protected MockClock $clock;
    protected Factory $factory;

    /** Set to false for tests that need committed data visible to other connections. */
    protected bool $useTransaction = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = TestContainer::get();
        $em = $this->container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        if (!$em->isOpen()) {
            TestContainer::reset();
            $this->container = TestContainer::get();
            $em = $this->container->get(EntityManagerInterface::class);
            \assert($em instanceof EntityManagerInterface);
        }
        $this->em = $em;
        $this->em->clear();
        $db = $this->container->get(Connection::class);
        \assert($db instanceof Connection);
        $this->db = $db;
        $clock = $this->container->get(ClockInterface::class);
        \assert($clock instanceof MockClock);
        $this->clock = $clock;
        $this->clock->modify(TestContainer::NOW);
        $pool = $this->container->get(AdapterInterface::class);
        \assert($pool instanceof AdapterInterface);
        $pool->clear();
        TestContainer::rateLimitStorage()->reset();
        $salts = $this->container->get(\Analytics\Tracking\Application\DailySaltProvider::class);
        if ($salts instanceof \Analytics\Tracking\Infrastructure\DbalDailySaltProvider) {
            // The provider memoises today's salt; rolled-back tests must not reuse it.
            $salts->forgetMemo();
        }

        if ($this->useTransaction) {
            $this->db->beginTransaction();
        } else {
            self::truncateAll($this->db);
        }
        $this->factory = new Factory($this->container);
    }

    protected function tearDown(): void
    {
        if ($this->useTransaction) {
            while ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
        } else {
            self::truncateAll($this->db);
        }
        $this->em->clear();
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = $this->container->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    /** Empties the application cache pool (site snapshots, report cache). */
    protected function clearCaches(): void
    {
        $pool = $this->container->get(AdapterInterface::class);
        \assert($pool instanceof AdapterInterface);
        $pool->clear();
    }

    public static function truncateAll(Connection $db): void
    {
        $tables = $db->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name <> 'doctrine_migration_versions'");
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $db->executeStatement('TRUNCATE TABLE `' . $table . '`');
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}
