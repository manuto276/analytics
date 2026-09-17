<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Reporting\Domain\DateRange;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteSnapshot;
use Doctrine\DBAL\Connection;

final readonly class ConsentReport
{
    public function __construct(private Connection $connection) {}

    /** @return array<string, mixed> */
    public function run(SiteSnapshot $site, DateRange $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT day, consent_version, shown, accepted, rejected, dismissed, reopened
               FROM consent_stats_daily WHERE site_id = ? AND day BETWEEN ? AND ? ORDER BY day, consent_version',
            [$site->id, $range->fromDay(), $range->toDay()],
        );
        $totals = ['shown' => 0, 'accepted' => 0, 'rejected' => 0, 'dismissed' => 0, 'reopened' => 0];
        $data = [];
        foreach ($rows as $row) {
            $entry = [
                'day' => Types::string($row['day']),
                'consent_version' => Types::int($row['consent_version']),
                'shown' => Types::int($row['shown']),
                'accepted' => Types::int($row['accepted']),
                'rejected' => Types::int($row['rejected']),
                'dismissed' => Types::int($row['dismissed']),
                'reopened' => Types::int($row['reopened']),
            ];
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $entry[$key];
            }
            $data[] = $entry;
        }
        $decisions = $totals['accepted'] + $totals['rejected'] + $totals['dismissed'];

        return [
            'rows' => $data,
            'totals' => $totals + ['acceptance_rate' => $decisions > 0 ? round($totals['accepted'] / $decisions, 4) : null],
        ];
    }
}
