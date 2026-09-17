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

        // Filtered queries the rollups can answer: an unfiltered provider hid a filter that was
        // spliced into the ON clause of a LEFT JOIN, where it narrowed nothing.
        yield 'content by channel' => ['content', self::filter(['channel' => 'organic_search'])];
        yield 'content by content key' => ['content', self::filter(['content' => 'author:1'])];
        yield 'content by channel and content key' => ['content', self::filter(['channel' => 'organic_search', 'content' => 'author:1'])];
        yield 'content by content key reversed' => ['content', self::filter(['content' => 'author:1', 'channel' => 'organic_search'])];
        yield 'pages by page' => ['pages', ['limit' => '100'] + self::filter(['page' => '/blog/analytics-without-cookies'])];
        yield 'pages by host' => ['pages', ['limit' => '100'] + self::filter(['host' => 'www.example.com'])];
        yield 'sources by channel filtered' => ['sources', ['group' => 'channel'] + self::filter(['channel' => 'organic_search'])];
        yield 'sources by source filtered' => ['sources', ['group' => 'source'] + self::filter(['source' => 'Google'])];
        yield 'landing pages by channel' => ['landing-pages', ['limit' => '100'] + self::filter(['channel' => 'organic_search'])];
        yield 'landing pages by host' => ['landing-pages', ['limit' => '100'] + self::filter(['host' => 'www.example.com'])];
    }

    /**
     * @param array<string, string> $filters dimension => value (is)
     *
     * @return array{filter: array<string, array<string, string>>}
     */
    private static function filter(array $filters): array
    {
        return ['filter' => array_map(static fn(string $value): array => ['is' => $value], $filters)];
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

    /**
     * The same two filters in either order must give the same answer. Each translation of the
     * filter list (visits side, events side) numbers its own placeholders, and the two halves end up
     * in one query for the raw table reports; sharing the numbering made the visits half read the
     * next filter's value as soon as a dimension needed a different number of placeholders on the
     * two sides. `content` is exactly such a dimension.
     */
    public function testFilterOrderDoesNotChangeTheAnswer(): void
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        $content = ['content' => ['is' => 'author:1']];
        $page = ['page' => ['is' => '/blog/analytics-without-cookies']];

        $first = $this->report('pages', ['limit' => '100', 'filter' => $content + $page], [], true);
        $second = $this->report('pages', ['limit' => '100', 'filter' => $page + $content], [], true);

        self::assertNotSame([], $first['rows']);
        self::assertGreaterThan(0, array_sum(array_column($first['rows'], 'exits')), 'the report must attribute exits, or the order cannot be told apart');
        self::assertEquals($second, $first, 'the filter order changed the answer');
    }

    /**
     * Adding a filter must narrow a report, never change what the other filters mean. Channels
     * partition the data, so filtering by device and then splitting that by channel must add back up
     * to the device-only total.
     */
    public function testANarrowingFilterKeepsTheMeaningOfTheOtherFilters(): void
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        $channels = $this->db->fetchFirstColumn('SELECT DISTINCT channel FROM visits WHERE site_id = ? UNION SELECT DISTINCT channel FROM events_raw WHERE site_id = ?', [$dataset->site->id(), $dataset->site->id()]);
        self::assertGreaterThan(1, \count($channels));

        foreach (['content' => 'pageviews', 'pages' => 'pageviews', 'sources' => 'visits', 'landing-pages' => 'entries'] as $report => $metric) {
            $device = ['device' => ['is' => 'desktop']];
            $total = $this->metricSum($report, ['filter' => $device], $metric);
            self::assertGreaterThan(0, $total, $report . ' has no data to split');
            $split = 0;
            foreach ($channels as $channel) {
                $split += $this->metricSum($report, ['filter' => $device + ['channel' => ['is' => (string) $channel]]], $metric);
            }
            self::assertSame($total, $split, 'the channel split of ' . $report . ' does not add up to the device-only total');
        }
    }

    /**
     * A filter that only the visit carries (here: the page the visit left on) must narrow the
     * content report. It used to be spliced into the ON clause of the LEFT JOIN, where it made the
     * visit NULL instead of dropping the row — or be rejected outright as "needs visit data".
     */
    public function testContentReportAppliesVisitScopedFilters(): void
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        $all = $this->metricSum('content', [], 'pageviews');
        $filtered = $this->metricSum('content', ['filter' => ['exit_page' => ['is' => '/pricing']]], 'pageviews');

        $expected = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM events_raw e
               JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day
              WHERE e.site_id = :site AND e.type = 'pv' AND e.content_key IS NOT NULL AND e.local_day BETWEEN :from AND :to
                AND EXISTS (SELECT 1 FROM events_raw x WHERE x.site_id = v.site_id AND x.local_day = v.local_day
                              AND x.visit_id = v.id AND x.page_hash = v.exit_page_hash AND x.path = '/pricing')",
            ['site' => $dataset->site->id(), 'from' => $this->clock->now()->modify('-29 days')->format('Y-m-d'), 'to' => $this->clock->now()->format('Y-m-d')],
        );
        self::assertGreaterThan(0, $filtered);
        self::assertLessThan($all, $filtered, 'the filter did not narrow the report');
        self::assertSame($expected, $filtered);
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function report(string $report, array $params, array $options = [], bool $forceRaw = false): array
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);
        // The cache fingerprint sorts the filters, so both orders of one filter list share an entry.
        $this->clearCaches();

        return $this->service(ReportService::class)->run(
            $report,
            $this->service(ReportQueryFactory::class)->create($dataset->snapshot, ['period' => '30d', 'limit' => '1000'] + $params, $options, $forceRaw),
        )['data'];
    }

    /** @param array<string, mixed> $params */
    private function metricSum(string $report, array $params, string $metric): int
    {
        $rows = $this->report($report, $params, [], true)['rows'] ?? [];
        \assert(\is_array($rows));

        return (int) array_sum(array_column($rows, $metric));
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
