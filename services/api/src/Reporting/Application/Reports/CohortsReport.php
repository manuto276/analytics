<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Returning consented visitors by first-seen cohort. Within retention the cohorts come from raw
 * visits; older months fall back to rollup_consented_visitors_monthly.
 */
final readonly class CohortsReport
{
    public function __construct(private Connection $connection, private ClockInterface $clock, private int $retentionMonths) {}

    /** @return array<string, mixed> */
    public function run(SiteSnapshot $site, string $cohort, int $periods): array
    {
        $timezone = $site->timezone();
        $now = $this->clock->now()->setTimezone($timezone);
        $format = $cohort === 'week' ? '%x-W%v' : '%Y-%m';
        $start = $cohort === 'week'
            ? $now->modify('monday this week')->modify('-' . ($periods - 1) . ' weeks')
            : new \DateTimeImmutable($now->format('Y-m-01'), $timezone)->modify('-' . ($periods - 1) . ' months');

        $retentionStart = $now->modify('-' . $this->retentionMonths . ' months');
        if ($start < $retentionStart) {
            $start = $retentionStart;
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DATE_FORMAT(vr.first_seen_at, '{$format}') AS cohort, DATE_FORMAT(v.started_at, '{$format}') AS period,
                    COUNT(DISTINCT v.visitor_id) AS visitors
               FROM visits v
               JOIN visitors vr ON vr.site_id = v.site_id AND vr.visitor_id = v.visitor_id
              WHERE v.site_id = :site AND v.visitor_id IS NOT NULL AND v.local_day >= :start
              GROUP BY cohort, period",
            ['site' => $site->id, 'start' => $start->format('Y-m-d')],
        );

        $labels = [];
        for ($i = 0; $i < $periods; ++$i) {
            $date = $cohort === 'week' ? $start->modify('+' . $i . ' weeks') : $start->modify('+' . $i . ' months');
            $labels[] = $cohort === 'week' ? $date->format('o-\WW') : $date->format('Y-m');
        }
        $index = array_flip($labels);

        $matrix = [];
        foreach ($rows as $row) {
            $cohortLabel = Types::string($row['cohort']);
            $periodLabel = Types::string($row['period']);
            if (!isset($index[$cohortLabel], $index[$periodLabel])) {
                continue;
            }
            $matrix[$cohortLabel][$index[$periodLabel] - $index[$cohortLabel]] = Types::int($row['visitors']);
        }

        $data = [];
        foreach ($labels as $position => $label) {
            $size = $matrix[$label][0] ?? 0;
            $retained = [];
            for ($offset = 0; $offset < $periods - $position; ++$offset) {
                $visitors = $matrix[$label][$offset] ?? 0;
                $retained[] = $size > 0 ? round($visitors / $size, 4) : null;
            }
            $data[] = ['cohort' => $label, 'size' => $size, 'retained' => $retained];
        }

        return ['cohort' => $cohort, 'rows' => $data];
    }
}
