<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Application\Rollup\RawSelects;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Filter;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteSnapshot;
use Doctrine\DBAL\Connection;

/**
 * Headline metrics per local day, either from rollup_overview_daily or straight from raw data
 * (same formulas, so both paths agree).
 */
final readonly class DailyMetrics
{
    public const array EMPTY = [
        'pageviews' => 0, 'visits' => 0, 'visitors' => 0, 'bounces' => 0, 'engagement_ms' => 0,
        'events' => 0, 'consented_visits' => 0, 'conversions' => 0, 'revenue_minor' => 0,
    ];

    public function __construct(private Connection $connection, private SqlFilters $filters) {}

    /**
     * @param list<Filter> $filters
     *
     * @return array<string, array<string, int>> day => metrics
     */
    public function raw(SiteSnapshot $site, DateRange $range, array $filters = []): array
    {
        $visitWhere = $this->filters->forVisits($filters);
        $eventWhere = $this->filters->forEvents($filters, true);
        $params = ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay(), 'currency' => $site->currency];
        $days = self::emptyDays($range);

        $visitSql = RawSelects::visitMetrics(' AND v.local_day BETWEEN :from AND :to' . $visitWhere['sql']);
        foreach ($this->connection->fetchAllAssociative($visitSql, $params + $visitWhere['params']) as $row) {
            $day = Types::string($row['day']);
            $days[$day]['visits'] += Types::int($row['visits']);
            $days[$day]['visitors'] += Types::int($row['visitors']);
            $days[$day]['bounces'] += Types::int($row['bounces']);
            $days[$day]['engagement_ms'] += Types::int($row['engagement_ms']);
            $days[$day]['consented_visits'] += Types::int($row['consented_visits']);
        }

        $eventSql = RawSelects::eventMetrics(' AND e.local_day BETWEEN :from AND :to' . $eventWhere['sql'], $filters !== []);
        foreach ($this->connection->fetchAllAssociative($eventSql, $params + $eventWhere['params']) as $row) {
            $day = Types::string($row['day']);
            $days[$day]['pageviews'] += Types::int($row['pageviews']);
            $days[$day]['events'] += Types::int($row['events']);
            $days[$day]['visits'] += Types::int($row['orphan_entries']);
        }

        if ($filters === []) {
            $sql = RawSelects::conversionMetrics(' AND c.local_day BETWEEN :from AND :to');
            foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
                $day = Types::string($row['day']);
                $days[$day]['conversions'] += Types::int($row['conversions']);
                $days[$day]['revenue_minor'] += Types::int($row['revenue_minor']);
            }
        }

        return $days;
    }

    /** @return array<string, array<string, int>> day => metrics */
    public function rollup(SiteSnapshot $site, DateRange $range): array
    {
        $days = self::emptyDays($range);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT day, pageviews, visits, visitors, bounces, engagement_ms, events, consented_visits, conversions, revenue_minor
               FROM rollup_overview_daily WHERE site_id = :site AND day BETWEEN :from AND :to',
            ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()],
        );
        foreach ($rows as $row) {
            $day = Types::string($row['day']);
            foreach (array_keys(self::EMPTY) as $metric) {
                $days[$day][$metric] = Types::int($row[$metric] ?? 0);
            }
        }

        return $days;
    }

    /**
     * @param array<string, array<string, int>> $days
     *
     * @return array<string, int>
     */
    public static function sum(array $days): array
    {
        $total = self::EMPTY;
        foreach ($days as $metrics) {
            foreach ($metrics as $key => $value) {
                $total[$key] += $value;
            }
        }

        return $total;
    }

    /**
     * Derived metrics for the API envelope.
     *
     * @param array<string, int> $totals
     *
     * @return array<string, int|float|null>
     */
    public static function present(array $totals, bool $visitorsAvailable, bool $bounceAvailable, bool $durationAvailable): array
    {
        $visits = $totals['visits'];

        return [
            'visitors' => $visitorsAvailable ? $totals['visitors'] : null,
            'visits' => $visits,
            'pageviews' => $totals['pageviews'],
            'views_per_visit' => $visits > 0 ? round($totals['pageviews'] / $visits, 2) : null,
            'bounce_rate' => $bounceAvailable && $visits > 0 ? round($totals['bounces'] / $visits, 4) : null,
            'avg_duration_ms' => $durationAvailable && $visits > 0 ? (int) round($totals['engagement_ms'] / $visits) : null,
            'events' => $totals['events'],
            'conversions' => $totals['conversions'],
            'revenue_minor' => $totals['revenue_minor'],
            'consented_visits' => $totals['consented_visits'],
            'consent_rate' => $visits > 0 ? round($totals['consented_visits'] / $visits, 4) : null,
        ];
    }

    /** @return array<string, array<string, int>> */
    private static function emptyDays(DateRange $range): array
    {
        $days = [];
        foreach ($range->dayList() as $day) {
            $days[$day] = self::EMPTY;
        }

        return $days;
    }
}
