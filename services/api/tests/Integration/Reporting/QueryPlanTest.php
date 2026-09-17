<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Reporting;

use Analytics\Reporting\Application\Reports\TimeseriesReport;
use Analytics\Reporting\Application\Rollup\RawSelects;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tracking\Application\Seeder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Report queries must use indexes and partition pruning: no full scan of events_raw or visits,
 * and never more partitions than the requested range needs.
 */
final class QueryPlanTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    private int $siteId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $site = $this->factory->site(['cookieLevelEnabled' => true], ['www.example.com']);
        $this->siteId = $site->id();
        $this->service(Seeder::class)->seed(SiteSnapshot::fromSite($site), $this->clock->now(), 3, 5, 7);
    }

    /** @return iterable<string, array{string}> */
    public static function queries(): iterable
    {
        $range = ' AND e.local_day BETWEEN :from AND :to';
        $visitRange = ' AND v.local_day BETWEEN :from AND :to';
        yield 'daily visit metrics' => [RawSelects::visitMetrics($visitRange)];
        yield 'daily event metrics' => [RawSelects::eventMetrics($range)];
        // Filtered overview/timeseries queries join visits to read the visit dimensions on an event.
        yield 'daily event metrics joined to visits' => [RawSelects::eventMetrics($range, true, $visitRange)];
        yield 'hourly event metrics joined to visits' => [TimeseriesReport::hourlyEvents("DATE_FORMAT(CONVERT_TZ(e.occurred_at, '+00:00', :offset), '%Y-%m-%d %H:00')", '', true, $visitRange)];
        yield 'pages' => [RawSelects::pages($range, $visitRange)];
        // The report path joins visits so attribution filters can resolve through COALESCE(v.…, e.…).
        yield 'pages joined to visits' => [RawSelects::pages($range, $visitRange, true, $visitRange)];
        yield 'landing pages' => [RawSelects::landing($range, $visitRange)];
        yield 'sources' => [RawSelects::sources($range, $visitRange)];
        yield 'campaigns' => [RawSelects::campaigns($visitRange)];
        yield 'tech' => [RawSelects::tech('device', $range, $visitRange)];
        yield 'tech joined to visits' => [RawSelects::tech('device', $range, $visitRange, true, $visitRange)];
        yield 'geo' => [RawSelects::geo($range, $visitRange)];
        yield 'geo joined to visits' => [RawSelects::geo($range, $visitRange, true, $visitRange)];
        yield 'events' => [RawSelects::events($range)];
        yield 'events joined to visits' => [RawSelects::events($range, true, $visitRange)];
        yield 'event props' => [RawSelects::eventProps($range)];
        yield 'event props joined to visits' => [RawSelects::eventProps($range, true, $visitRange)];
        yield 'content' => [RawSelects::content($range, $visitRange)];
    }

    #[DataProvider('queries')]
    public function testRawReportQueriesUseIndexesAndPartitionPruning(string $sql): void
    {
        $params = [
            'site' => $this->siteId,
            'from' => $this->clock->now()->modify('-2 days')->format('Y-m-d'),
            'to' => $this->clock->now()->format('Y-m-d'),
            'currency' => 'EUR',
            'contacts' => ['contact_form'],
        ];
        $types = ['contacts' => \Doctrine\DBAL\ArrayParameterType::STRING];
        if (!str_contains($sql, ':contacts')) {
            unset($params['contacts'], $types['contacts']);
        }
        if (!str_contains($sql, ':currency')) {
            unset($params['currency']);
        }
        if (str_contains($sql, ':offset')) {
            $params['offset'] = '+02:00';
        }

        $plan = $this->db->fetchAllAssociative('EXPLAIN ' . $sql, $params, $types);
        self::assertNotSame([], $plan);
        // Only events_raw and visits are partitioned, so a non-empty "partitions" column marks them.
        $checked = 0;
        foreach ($plan as $row) {
            $partitions = Types::nullableString($row['partitions'] ?? null);
            if ($partitions === null || $partitions === '') {
                continue;
            }
            ++$checked;
            $where = json_encode($row, \JSON_THROW_ON_ERROR);
            // Pruning is what keeps a 13-month table cheap: only the months of the range may be read.
            self::assertLessThanOrEqual(2, \count(explode(',', $partitions)), 'too many partitions read: ' . $partitions);
            self::assertStringContainsString('idx_', Types::string($row['possible_keys'] ?? '', ''), 'no usable index for this access path: ' . $where);
        }
        self::assertGreaterThan(0, $checked, 'the query does not touch events_raw or visits: ' . json_encode($plan, \JSON_THROW_ON_ERROR));
    }

    /**
     * With enough rows the optimiser must pick an index instead of reading the whole partition
     * when only one site and one day are requested.
     */
    public function testSelectiveQueriesUseAnIndex(): void
    {
        $other = $this->factory->site([], ['other.example.com']);
        $this->service(Seeder::class)->seed(SiteSnapshot::fromSite($other), $this->clock->now(), 3, 120, 11);
        $this->db->fetchAllAssociative('ANALYZE TABLE events_raw, visits');

        $day = $this->clock->now()->format('Y-m-d');
        $plan = $this->db->fetchAllAssociative(
            "EXPLAIN SELECT COUNT(*) FROM events_raw e WHERE e.site_id = ? AND e.local_day = ? AND e.type = 'pv'",
            [$this->siteId, $day],
        );
        self::assertSame('ref', Types::string($plan[0]['type'] ?? ''), 'the (site, day, type) index must be used: ' . json_encode($plan, \JSON_THROW_ON_ERROR));
        self::assertSame('idx_events_site_day_type', Types::string($plan[0]['key'] ?? ''));
        $total = Types::int($this->db->fetchOne('SELECT COUNT(*) FROM events_raw'));
        self::assertLessThan($total, Types::int($plan[0]['rows'] ?? 0), 'the plan still examines every row');

        $visitPlan = $this->db->fetchAllAssociative('EXPLAIN SELECT COUNT(*) FROM visits v WHERE v.site_id = ? AND v.local_day = ?', [$this->siteId, $day]);
        self::assertSame('ref', Types::string($visitPlan[0]['type'] ?? ''));
        self::assertSame('idx_visits_site_day', Types::string($visitPlan[0]['key'] ?? ''));
    }

    public function testRollupQueriesUseThePrimaryKey(): void
    {
        foreach (['rollup_overview_daily', 'rollup_pages_daily', 'rollup_sources_daily', 'rollup_events_daily', 'rollup_conversions_daily'] as $table) {
            $plan = $this->db->fetchAllAssociative(
                'EXPLAIN SELECT * FROM ' . $table . ' WHERE site_id = ? AND day BETWEEN ? AND ?',
                [$this->siteId, '2026-09-01', '2026-09-30'],
            );
            self::assertSame('range', Types::string($plan[0]['type'] ?? ''), $table . ' does not use a range scan on the primary key');
        }
    }
}
