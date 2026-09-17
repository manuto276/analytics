<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Reporting;

use Analytics\Reporting\Application\ReportQueryFactory;
use Analytics\Reporting\Application\ReportService;
use Analytics\Shared\Types;
use Analytics\Tests\Support\Factory;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tests\Support\Scenario\SeededDataset;
use Analytics\Tests\Support\TestContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * `entry_page` and `exit_page` describe the *visit*, and every report must be able to answer them
 * on the raw path — they used to throw "needs visit data" (a 500 on a plain query parameter) on
 * every report whose raw query had no visits join.
 *
 * Each case pairs one of the two dimensions with a second filter the rollup cannot answer, which is
 * what forces the raw path, and checks the narrowed figure against a direct SQL oracle.
 */
final class VisitPageFilterTest extends IntegrationTestCase
{
    /** A page that is an entry page of some visits and the exit page of others. */
    private const string PAGE = '/pricing';
    /** The second filter: not covered by any of these rollups, so the planner must choose raw. */
    private const string DEVICE = 'desktop';

    private static ?SeededDataset $dataset = null;

    /** The dataset is committed once per class; every test then runs inside its own transaction. */
    public static function setUpBeforeClass(): void
    {
        $container = TestContainer::get();
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);
        self::truncateAll($connection);
        $clock = $container->get(ClockInterface::class);
        \assert($clock instanceof MockClock);
        $clock->modify(TestContainer::NOW);
        self::$dataset = new SeededDataset($container, new Factory($container), $clock->now());
        self::$dataset->buildRollups();
    }

    public static function tearDownAfterClass(): void
    {
        $container = TestContainer::get();
        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);
        self::truncateAll($connection);
        self::$dataset = null;
    }

    /**
     * Every filterable report, the metric that the filter has to narrow, and the oracle that
     * recomputes it straight from the raw tables. `{visit}` is replaced by the condition that picks
     * the visits the filter selects, so the oracle never shares code with the translation it checks.
     *
     * @return iterable<string, array{string, array<string, string>, string, string}>
     */
    public static function reports(): iterable
    {
        $events = 'FROM events_raw e JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day
                    WHERE e.site_id = :site AND e.local_day BETWEEN :from AND :to AND e.device = :device AND {visit}';
        $visits = 'FROM visits v WHERE v.site_id = :site AND v.local_day BETWEEN :from AND :to AND v.device = :device AND {visit}';

        yield 'overview' => ['overview', [], 'visits', 'SELECT COUNT(*) ' . $visits];
        yield 'timeseries' => ['timeseries', ['interval' => 'day'], 'visits', 'SELECT COUNT(*) ' . $visits];
        yield 'pages' => ['pages', [], 'pageviews', "SELECT COUNT(*) {$events} AND e.type = 'pv'"];
        yield 'landing pages' => ['landing-pages', [], 'entries', 'SELECT COUNT(*) ' . $visits . ' AND v.pageviews > 0'];
        yield 'sources' => ['sources', ['group' => 'channel'], 'visits', 'SELECT COUNT(*) ' . $visits];
        yield 'campaigns' => ['campaigns', [], 'visits', 'SELECT COUNT(*) ' . $visits . ' AND (v.utm_source IS NOT NULL OR v.utm_medium IS NOT NULL OR v.utm_campaign IS NOT NULL)'];
        yield 'tech' => ['tech', ['group' => 'device'], 'visits', 'SELECT COUNT(*) ' . $visits];
        yield 'countries' => ['countries', [], 'visits', 'SELECT COUNT(*) ' . $visits];
        yield 'events' => ['events', [], 'occurrences', "SELECT COUNT(*) {$events} AND e.type = 'ev' AND e.name IS NOT NULL"];
        // One seeded property per custom event, so one rollup row per event.
        yield 'event props' => ['event-props', ['event' => 'signup_click'], 'occurrences', "SELECT COUNT(*) {$events} AND e.type = 'ev' AND e.name = 'signup_click' AND e.props IS NOT NULL"];
        yield 'content' => ['content', [], 'pageviews', "SELECT COUNT(*) {$events} AND e.type = 'pv' AND e.content_key IS NOT NULL"];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('reports')]
    public function testEntryPageFilterNarrowsEveryReport(string $report, array $options, string $metric, string $oracle): void
    {
        $this->assertFilterMatchesOracle($report, $options, $metric, $oracle, 'entry_page');
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('reports')]
    public function testExitPageFilterNarrowsEveryReport(string $report, array $options, string $metric, string $oracle): void
    {
        $this->assertFilterMatchesOracle($report, $options, $metric, $oracle, 'exit_page');
    }

    /** @param array<string, string> $options */
    private function assertFilterMatchesOracle(string $report, array $options, string $metric, string $oracle, string $dimension): void
    {
        $device = ['device' => ['is' => self::DEVICE]];
        $filtered = $this->metric($report, $options, $device + [$dimension => ['is' => self::PAGE]], $metric, true);
        // The baseline is only there to show the filter narrows; `tech` answers it from its rollup.
        $deviceOnly = $this->metric($report, $options, $device, $metric, false);

        $expected = Types::int($this->db->fetchOne(
            str_replace('{visit}', self::visitCondition($dimension), $oracle),
            [
                'site' => $this->dataset()->site->id(),
                'from' => $this->clock->now()->modify('-29 days')->format('Y-m-d'),
                'to' => $this->clock->now()->format('Y-m-d'),
                'device' => self::DEVICE,
                'page' => self::PAGE,
            ],
        ));

        self::assertGreaterThan(0, $expected, 'the fixture has nothing to narrow to; pick another page');
        self::assertSame($expected, $filtered, $report . ' does not agree with the SQL oracle for ' . $dimension);
        self::assertLessThan($deviceOnly, $filtered, $dimension . ' did not narrow ' . $report);
    }

    /** The visits the filter selects, written out independently of SqlFilters. */
    private static function visitCondition(string $dimension): string
    {
        return $dimension === 'entry_page'
            ? 'v.entry_path = :page'
            : 'EXISTS (SELECT 1 FROM events_raw x WHERE x.site_id = v.site_id AND x.local_day = v.local_day
                         AND x.visit_id = v.id AND x.page_hash = v.exit_page_hash AND x.path = :page)';
    }

    /**
     * Runs the report and adds up one metric over every row (or point).
     *
     * @param array<string, string>                $options
     * @param array<string, array<string, string>> $filter
     */
    private function metric(string $report, array $options, array $filter, string $metric, bool $mustBeRaw): int
    {
        $this->clearCaches();
        $query = $this->service(ReportQueryFactory::class)->create(
            $this->dataset()->snapshot,
            ['period' => '30d', 'limit' => '1000', 'filter' => $filter],
            $options,
        );
        $result = $this->service(ReportService::class)->run($report, $query);
        if ($mustBeRaw) {
            self::assertSame('raw', $result['meta']['source'], $report . ' must be answered from raw data with these filters');
        }

        $data = $result['data'];
        if (\is_array($data['metrics'] ?? null)) {
            return Types::int($data['metrics'][$metric]);
        }
        $rows = $data['rows'] ?? $data['points'] ?? [];
        \assert(\is_array($rows));
        self::assertLessThanOrEqual(1000, \count($rows), 'the page of rows is not the whole answer');

        return (int) array_sum(array_column($rows, $metric));
    }

    private function dataset(): SeededDataset
    {
        $dataset = self::$dataset;
        self::assertNotNull($dataset);

        return $dataset;
    }
}
