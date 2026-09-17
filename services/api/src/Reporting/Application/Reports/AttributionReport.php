<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Reporting\Domain\DateRange;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\Connection;

/**
 * Conversions grouped by the channel, source or campaign they are attributed to, with campaign cost,
 * customer acquisition cost and return on ad spend. Touches older than the window do not count.
 */
final readonly class AttributionReport
{
    public const array MODELS = ['first_touch', 'last_non_direct', 'declared'];
    public const array GROUPS = ['channel', 'source', 'campaign'];
    public const array WINDOWS = [7, 30, 90];

    public function __construct(private Connection $connection) {}

    /**
     * @return array<string, mixed>
     */
    public function run(SiteSnapshot $site, DateRange $range, string $model, string $group, int $windowDays, ?string $base, ?string $target): array
    {
        if (!\in_array($model, self::MODELS, true)) {
            throw ApiProblem::validation(['model' => ['Must be one of: ' . implode(', ', self::MODELS) . '.']]);
        }
        if (!\in_array($group, self::GROUPS, true)) {
            throw ApiProblem::validation(['group' => ['Must be one of: ' . implode(', ', self::GROUPS) . '.']]);
        }
        if (!\in_array($windowDays, self::WINDOWS, true)) {
            throw ApiProblem::validation(['window' => ['Must be one of: ' . implode(', ', array_map('strval', self::WINDOWS)) . '.']]);
        }

        [$channelColumn, $sourceColumn, $campaignColumn, $touchedAt] = match ($model) {
            'first_touch' => ['attr_channel', 'attr_source', 'attr_utm_campaign', 'attr_touched_at'],
            'last_non_direct' => ['lnd_channel', 'lnd_source', 'lnd_utm_campaign', 'lnd_touched_at'],
            default => ['declared_channel', 'declared_source_name', 'declared_campaign', null],
        };

        $params = ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay(), 'currency' => $site->currency, 'window' => $windowDays];
        if ($model === 'declared') {
            $select = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.declared_source, '$.channel')), 'unattributed') AS channel,
                       JSON_UNQUOTE(JSON_EXTRACT(c.declared_source, '$.utm_source')) AS source,
                       JSON_UNQUOTE(JSON_EXTRACT(c.declared_source, '$.utm_campaign')) AS campaign";
            $windowCondition = '';
        } else {
            $select = "c.{$channelColumn} AS channel, c.{$sourceColumn} AS source, c.{$campaignColumn} AS campaign";
            $windowCondition = " AND (c.{$touchedAt} IS NULL OR c.{$touchedAt} >= c.occurred_at - INTERVAL :window DAY)";
        }

        if ($base !== null) {
            $params['base'] = $base;
        }
        if ($target !== null) {
            $params['target'] = $target;
        }

        $baseExpression = $base === null ? '1' : 'c.name = :base';
        $targetExpression = $target === null ? '1' : 'c.name = :target';
        $revenueCondition = $target === null ? '' : ' AND c.name = :target';
        $sql = \sprintf(
            'SELECT %s, SUM(%s) AS base_conversions, SUM(%s) AS target_conversions,
                    COALESCE(SUM(IF(c.currency = :currency%s, c.value_minor, 0)), 0) AS revenue_minor
               FROM conversions c
              WHERE c.site_id = :site AND c.local_day BETWEEN :from AND :to%s
              GROUP BY channel, source, campaign',
            $select,
            $baseExpression,
            $targetExpression,
            $revenueCondition,
            $windowCondition,
        );
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $costs = $this->costs($site, $range, $group);

        $grouped = [];
        $totals = ['base_conversions' => 0, 'target_conversions' => 0, 'revenue_minor' => 0, 'cost_minor' => 0, 'unattributed' => 0, 'conversions' => 0];
        foreach ($rows as $row) {
            $channel = Types::string($row['channel'], 'unattributed');
            $source = Types::nullableString($row['source']);
            $campaign = Types::nullableString($row['campaign']);
            $key = match ($group) {
                'source' => $source ?? $channel,
                'campaign' => $campaign ?? '(no campaign)',
                default => $channel,
            };
            $entry = $grouped[$key] ?? [
                'key' => $key,
                'channel' => $channel,
                'source' => $source,
                'utm_campaign' => $campaign,
                'visits' => null,
                'base_conversions' => 0,
                'target_conversions' => 0,
                'revenue_minor' => 0,
                'cost_minor' => null,
                'cac_minor' => null,
                'roas' => null,
            ];
            $entry['base_conversions'] += Types::int($row['base_conversions']);
            $entry['target_conversions'] += Types::int($row['target_conversions']);
            $entry['revenue_minor'] += Types::int($row['revenue_minor']);
            $grouped[$key] = $entry;
            $totals['base_conversions'] += Types::int($row['base_conversions']);
            $totals['target_conversions'] += Types::int($row['target_conversions']);
            $totals['revenue_minor'] += Types::int($row['revenue_minor']);
            $totals['conversions'] += Types::int($row['base_conversions']);
            if ($channel === 'unattributed') {
                $totals['unattributed'] += Types::int($row['base_conversions']);
            }
        }

        $visits = $this->visits($site, $range, $group);
        foreach ($grouped as $key => $entry) {
            $cost = $costs[$key] ?? null;
            $entry['visits'] = $visits[$key] ?? null;
            $entry['cost_minor'] = $cost;
            $entry['cac_minor'] = $cost !== null && $entry['target_conversions'] > 0 ? (int) round($cost / $entry['target_conversions']) : null;
            $entry['roas'] = $cost !== null && $cost > 0 ? round($entry['revenue_minor'] / $cost, 4) : null;
            $grouped[$key] = $entry;
            $totals['cost_minor'] += $cost ?? 0;
        }
        // Groups with cost but no conversion still matter for the spend total.
        foreach ($costs as $key => $cost) {
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'key' => (string) $key,
                    'channel' => $group === 'channel' ? (string) $key : null,
                    'source' => $group === 'source' ? (string) $key : null,
                    'utm_campaign' => $group === 'campaign' ? (string) $key : null,
                    'visits' => $visits[$key] ?? null,
                    'base_conversions' => 0,
                    'target_conversions' => 0,
                    'revenue_minor' => 0,
                    'cost_minor' => $cost,
                    'cac_minor' => null,
                    'roas' => null,
                ];
                $totals['cost_minor'] += $cost;
            }
        }

        $data = array_values($grouped);
        usort($data, static fn(array $a, array $b): int => [$b['target_conversions'], $b['base_conversions']] <=> [$a['target_conversions'], $a['base_conversions']]);

        return [
            'model' => $model,
            'group' => $group,
            'window_days' => $windowDays,
            'base' => $base,
            'target' => $target,
            'rows' => $data,
            'totals' => [
                'base_conversions' => $totals['base_conversions'],
                'target_conversions' => $totals['target_conversions'],
                'revenue_minor' => $totals['revenue_minor'],
                'cost_minor' => $totals['cost_minor'],
                'unattributed_share' => $totals['conversions'] > 0 ? round($totals['unattributed'] / $totals['conversions'], 4) : null,
            ],
        ];
    }

    /**
     * Campaign cost overlapping the range, prorated by day and grouped like the report.
     *
     * @return array<string, int>
     */
    private function costs(SiteSnapshot $site, DateRange $range, string $group): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT day_from, day_to, channel, utm_source, utm_campaign, amount_minor, currency
               FROM campaign_costs WHERE site_id = ? AND day_from <= ? AND day_to >= ? AND currency = ?',
            [$site->id, $range->toDay(), $range->fromDay(), $site->currency],
        );
        $costs = [];
        foreach ($rows as $row) {
            $from = new \DateTimeImmutable(Types::string($row['day_from']));
            $to = new \DateTimeImmutable(Types::string($row['day_to']));
            $totalDays = (int) $from->diff($to)->days + 1;
            $overlapFrom = max($from, $range->from);
            $overlapTo = min($to, $range->to);
            $overlapDays = (int) $overlapFrom->diff($overlapTo)->days + 1;
            $amount = (int) round(Types::int($row['amount_minor']) * $overlapDays / max(1, $totalDays));
            $key = match ($group) {
                'source' => Types::nullableString($row['utm_source']) ?? Types::string($row['channel'], 'unattributed'),
                'campaign' => Types::nullableString($row['utm_campaign']) ?? '(no campaign)',
                default => Types::string($row['channel'], 'unattributed'),
            };
            $costs[$key] = ($costs[$key] ?? 0) + $amount;
        }

        return $costs;
    }

    /**
     * Visits per group in the range, from the rollups.
     *
     * @return array<string, int>
     */
    private function visits(SiteSnapshot $site, DateRange $range, string $group): array
    {
        $params = ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()];
        $rows = match ($group) {
            'channel' => $this->connection->fetchAllAssociative('SELECT channel AS k, SUM(visits) AS visits FROM rollup_sources_daily WHERE site_id = :site AND day BETWEEN :from AND :to GROUP BY channel', $params),
            'source' => $this->connection->fetchAllAssociative('SELECT IFNULL(source, channel) AS k, SUM(visits) AS visits FROM rollup_sources_daily WHERE site_id = :site AND day BETWEEN :from AND :to GROUP BY k', $params),
            default => $this->connection->fetchAllAssociative("SELECT IFNULL(utm_campaign, '(no campaign)') AS k, SUM(visits) AS visits FROM rollup_campaigns_daily WHERE site_id = :site AND day BETWEEN :from AND :to GROUP BY k", $params),
        };
        $visits = [];
        foreach ($rows as $row) {
            $visits[Types::string($row['k'])] = Types::int($row['visits']);
        }

        return $visits;
    }
}
