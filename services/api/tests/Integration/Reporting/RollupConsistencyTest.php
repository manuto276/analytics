<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Reporting;

use Analytics\Reporting\Application\ReportQueryFactory;
use Analytics\Reporting\Application\ReportService;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tests\Support\Scenario\SeededDataset;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Golden invariant: a report answered from rollups and the same report answered from raw data
 * must return exactly the same numbers.
 */
final class RollupConsistencyTest extends IntegrationTestCase
{
    private static ?SeededDataset $dataset = null;

    /** The dataset is committed once per class; every test then runs inside its own transaction. */
    public static function setUpBeforeClass(): void
    {
        $container = \Analytics\Tests\Support\TestContainer::get();
        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        \assert($connection instanceof \Doctrine\DBAL\Connection);
        self::truncateAll($connection);
        $clock = $container->get(\Psr\Clock\ClockInterface::class);
        \assert($clock instanceof \Symfony\Component\Clock\MockClock);
        $clock->modify(\Analytics\Tests\Support\TestContainer::NOW);
        self::$dataset = new SeededDataset($container, new \Analytics\Tests\Support\Factory($container), $clock->now());
        self::$dataset->buildRollups();
    }

    public static function tearDownAfterClass(): void
    {
        $container = \Analytics\Tests\Support\TestContainer::get();
        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        \assert($connection instanceof \Doctrine\DBAL\Connection);
        self::truncateAll($connection);
        self::$dataset = null;
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function reports(): iterable
    {
        yield 'overview' => ['overview', []];
        yield 'overview compared' => ['overview', ['compare' => 'previous_period']];
        yield 'timeseries day' => ['timeseries', ['interval' => 'day']];
        yield 'timeseries week' => ['timeseries', ['interval' => 'week']];
        yield 'timeseries month' => ['timeseries', ['period' => '12mo', 'interval' => 'month']];
        yield 'pages top' => ['pages', ['limit' => '100']];
        yield 'pages entry' => ['pages', ['kind' => 'entry', 'limit' => '100']];
        yield 'pages exit' => ['pages', ['kind' => 'exit', 'limit' => '100']];
        yield 'landing pages' => ['landing-pages', ['limit' => '100']];
        yield 'sources by channel' => ['sources', ['group' => 'channel']];
        yield 'sources by source' => ['sources', ['group' => 'source']];
        yield 'sources by referrer' => ['sources', ['group' => 'referrer']];
        yield 'campaigns' => ['campaigns', []];
        yield 'tech device' => ['tech', ['group' => 'device']];
        yield 'tech browser' => ['tech', ['group' => 'browser']];
        yield 'tech os' => ['tech', ['group' => 'os']];
        yield 'countries' => ['countries', []];
        yield 'events' => ['events', []];
        yield 'event props' => ['event-props', ['event' => 'signup_click']];
        yield 'content' => ['content', []];
        yield 'content with prefix' => ['content', ['prefix' => 'author:']];
        yield 'conversions' => ['conversions', []];
        yield 'goals' => ['goals', []];
        yield 'sorted pages by visitors' => ['pages', ['sort' => 'visitors', 'limit' => '100']];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('reports')]
    public function testRollupAndRawAgree(string $report, array $params): void
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        $factory = $this->service(ReportQueryFactory::class);
        $service = $this->service(ReportService::class);
        $options = array_filter([
            'kind' => $params['kind'] ?? null,
            'group' => $params['group'] ?? null,
            'prefix' => $params['prefix'] ?? null,
            'event' => $params['event'] ?? null,
        ], static fn(mixed $v): bool => \is_string($v));
        $query = ['period' => '30d'] + $params;

        $fromRollup = $service->run($report, $factory->create($dataset->snapshot, $query, $options, false));
        $fromRaw = $service->run($report, $factory->create($dataset->snapshot, $query, $options, true));

        self::assertSame('rollup', $fromRollup['meta']['source'], $report . ' should be answered from rollups');
        self::assertSame('raw', $fromRaw['meta']['source']);
        self::assertEquals(
            json_decode(json_encode($fromRollup['data'], \JSON_THROW_ON_ERROR), true),
            json_decode(json_encode($fromRaw['data'], \JSON_THROW_ON_ERROR), true),
            'rollup and raw disagree for ' . $report,
        );
    }

    public function testRollupsAreIdempotentAndClearDirtyDays(): void
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        $before = $this->rollupSnapshot($dataset->site->id());
        self::assertNotSame([], $before);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ?', [$dataset->site->id()]));

        $runner = $this->service(RollupRunner::class);
        $runner->rebuild($dataset->site->id(), $this->clock->now()->modify('-40 days'), $this->clock->now());

        self::assertSame($before, $this->rollupSnapshot($dataset->site->id()), 'rebuilding must not change the numbers');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ?', [$dataset->site->id()]));
    }

    /** @return array<string, string> */
    private function rollupSnapshot(int $siteId): array
    {
        $snapshot = [];
        foreach (\Analytics\Reporting\Application\Rollup\RollupBuilder::DAILY_TABLES as $table) {
            $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . $table . ' WHERE site_id = ? ORDER BY 2, 3, 4', [$siteId]);
            $normalised = array_map(static fn(array $row): array => array_map(static fn(mixed $value): string => \is_string($value) ? base64_encode($value) : var_export($value, true), $row), $rows);
            $snapshot[$table] = md5(serialize($normalised));
        }

        return $snapshot;
    }
}
