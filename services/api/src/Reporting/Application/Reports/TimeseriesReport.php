<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Reporting\Application\DailyMetrics;
use Analytics\Reporting\Application\SqlFilters;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Interval;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;

/**
 * Metrics per interval. Day, week and month come from daily metrics (rollup or raw);
 * the hour interval always reads raw events within a short range.
 */
final readonly class TimeseriesReport
{
    public function __construct(
        private Connection $connection,
        private DailyMetrics $metrics,
        private SqlFilters $filters,
    ) {}

    /**
     * @return list<array<string, int|float|string|null>>
     */
    public function points(ReportQuery $query, DateRange $range, bool $useRollup, bool $visitorsAvailable, bool $bounceAvailable, bool $durationAvailable): array
    {
        if ($query->interval === Interval::Hour) {
            $days = $this->hourly($query, $range);
        } else {
            $days = $useRollup ? $this->metrics->rollup($query->site, $range) : $this->metrics->raw($query->site, $range, $query->filters);
            $days = self::bucket($days, $query->interval, $query->site->timezone());
        }

        $points = [];
        foreach ($days as $bucket => $totals) {
            $presented = DailyMetrics::present($totals, $visitorsAvailable, $bounceAvailable, $durationAvailable);
            $points[] = ['t' => (string) $bucket] + [
                'visitors' => $presented['visitors'],
                'visits' => $presented['visits'],
                'pageviews' => $presented['pageviews'],
                'bounce_rate' => $presented['bounce_rate'],
                'avg_duration_ms' => $presented['avg_duration_ms'],
                'conversions' => $presented['conversions'],
                'revenue_minor' => $presented['revenue_minor'],
            ];
        }

        return $points;
    }

    /**
     * @param array<string, array<string, int>> $days
     *
     * @return array<string, array<string, int>>
     */
    public static function bucket(array $days, Interval $interval, \DateTimeZone $timezone): array
    {
        $buckets = [];
        foreach ($days as $day => $metrics) {
            $date = new \DateTimeImmutable((string) $day, $timezone);
            $key = match ($interval) {
                Interval::Week => $date->modify('monday this week')->format('Y-m-d'),
                Interval::Month => $date->format('Y-m-01'),
                default => $date->format('Y-m-d'),
            };
            if (!isset($buckets[$key])) {
                $buckets[$key] = DailyMetrics::EMPTY;
            }
            foreach ($metrics as $name => $value) {
                $buckets[$key][$name] += $value;
            }
        }
        ksort($buckets);

        return $buckets;
    }

    /** @return array<string, array<string, int>> hour (site time) => metrics */
    private function hourly(ReportQuery $query, DateRange $range): array
    {
        $timezone = $query->site->timezone();
        $offset = $range->from->setTimezone($timezone)->format('P');
        $buckets = [];
        for ($hour = $range->from->setTime(0, 0); $hour <= $range->to->setTime(23, 0); $hour = $hour->modify('+1 hour')) {
            $buckets[$hour->format('Y-m-d H:00')] = DailyMetrics::EMPTY;
        }

        $visitFilters = $this->filters->forVisits($query->filters);
        $eventFilters = $this->filters->forEvents($query->filters, $query->filters !== []);
        $params = ['site' => $query->site->id, 'from' => $range->fromDay(), 'to' => $range->toDay(), 'offset' => $offset];
        $bucketExpression = static fn(string $column, string $alias): string => "DATE_FORMAT(CONVERT_TZ({$alias}.{$column}, '+00:00', :offset), '%Y-%m-%d %H:00')";

        $visitRows = $this->connection->fetchAllAssociative(
            'SELECT ' . $bucketExpression('started_at', 'v') . ' AS bucket, COUNT(*) AS visits,
                    COUNT(DISTINCT v.visitor_hash) + COUNT(DISTINCT CASE WHEN v.visitor_hash IS NULL THEN v.visitor_id END) AS visitors,
                    COALESCE(SUM(v.is_bounce), 0) AS bounces, COALESCE(SUM(v.engagement_ms), 0) AS engagement_ms,
                    COALESCE(SUM(v.level = \'c\'), 0) AS consented_visits
               FROM visits v WHERE v.site_id = :site AND v.local_day BETWEEN :from AND :to' . $visitFilters['sql'] . ' GROUP BY bucket',
            $params + $visitFilters['params'],
        );
        foreach ($visitRows as $row) {
            $this->add($buckets, Types::string($row['bucket']), ['visits' => Types::int($row['visits']), 'visitors' => Types::int($row['visitors']), 'bounces' => Types::int($row['bounces']), 'engagement_ms' => Types::int($row['engagement_ms']), 'consented_visits' => Types::int($row['consented_visits'])]);
        }

        $join = $query->filters !== [] ? ' LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day' : '';
        $eventRows = $this->connection->fetchAllAssociative(
            'SELECT ' . $bucketExpression('occurred_at', 'e') . ' AS bucket, COALESCE(SUM(e.type = \'pv\'), 0) AS pageviews,
                    COALESCE(SUM(e.type = \'ev\'), 0) AS events, COALESCE(SUM(e.type = \'pv\' AND e.visit_id IS NULL AND e.is_entry = 1), 0) AS orphan_entries
               FROM events_raw e' . $join . ' WHERE e.site_id = :site AND e.local_day BETWEEN :from AND :to' . $eventFilters['sql'] . ' GROUP BY bucket',
            $params + $eventFilters['params'],
        );
        foreach ($eventRows as $row) {
            $this->add($buckets, Types::string($row['bucket']), ['pageviews' => Types::int($row['pageviews']), 'events' => Types::int($row['events']), 'visits' => Types::int($row['orphan_entries'])]);
        }

        if ($query->filters === []) {
            $conversionRows = $this->connection->fetchAllAssociative(
                'SELECT ' . $bucketExpression('occurred_at', 'c') . ' AS bucket, COUNT(*) AS conversions,
                        COALESCE(SUM(IF(c.currency = :currency, c.value_minor, 0)), 0) AS revenue_minor
                   FROM conversions c WHERE c.site_id = :site AND c.local_day BETWEEN :from AND :to GROUP BY bucket',
                $params + ['currency' => $query->site->currency],
            );
            foreach ($conversionRows as $row) {
                $this->add($buckets, Types::string($row['bucket']), ['conversions' => Types::int($row['conversions']), 'revenue_minor' => Types::int($row['revenue_minor'])]);
            }
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * @param array<string, array<string, int>> $buckets
     * @param array<string, int>                $values
     */
    private function add(array &$buckets, string $bucket, array $values): void
    {
        if (!isset($buckets[$bucket])) {
            return;
        }
        foreach ($values as $key => $value) {
            $buckets[$bucket][$key] += $value;
        }
    }
}
