<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Conversions\Domain\Goal;
use Analytics\Conversions\Domain\GoalType;
use Analytics\Reporting\Application\DailyMetrics;
use Analytics\Reporting\Application\Rollup\RawSelects;
use Analytics\Reporting\Application\SqlFilters;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Goal completions: pageview goals count visits that saw a matching page, event goals count visits
 * with a matching event, conversion goals count server-side conversions.
 */
final readonly class GoalsReport
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $em,
        private DailyMetrics $metrics,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total_rows: int}
     */
    public function run(SiteSnapshot $site, DateRange $range, bool $useRollup): array
    {
        /** @var list<Goal> $goals */
        $goals = $this->em->getRepository(Goal::class)->findBy(['siteId' => $site->id], ['name' => 'ASC']);
        $totals = DailyMetrics::sum($useRollup ? $this->metrics->rollup($site, $range) : $this->metrics->raw($site, $range));
        $visits = $totals['visits'];
        $params = ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()];

        $rows = [];
        foreach ($goals as $goal) {
            $count = 0;
            $value = 0;
            $match = $goal->match;
            if ($goal->type === GoalType::Pageview) {
                $path = \is_string($match['path'] ?? null) ? $match['path'] : '';
                $like = SqlFilters::globToLike($path);
                $count = $useRollup
                    ? Types::int($this->connection->fetchOne('SELECT COALESCE(SUM(visits), 0) FROM rollup_pages_daily WHERE site_id = :site AND day BETWEEN :from AND :to AND path LIKE :path', $params + ['path' => $like]))
                    : Types::int($this->connection->fetchOne('SELECT COALESCE(SUM(visits), 0) FROM (' . RawSelects::pages(' AND e.local_day BETWEEN :from AND :to AND e.path LIKE :path', ' AND v.local_day BETWEEN :from AND :to AND 1 = 0') . ') parts', $params + ['path' => $like]));
            } elseif ($goal->type === GoalType::Event) {
                $name = \is_string($match['name'] ?? null) ? $match['name'] : '';
                $props = \is_array($match['props'] ?? null) ? $match['props'] : [];
                if ($props === []) {
                    $count = $useRollup
                        ? Types::int($this->connection->fetchOne("SELECT COALESCE(SUM(visits), 0) FROM rollup_events_daily WHERE site_id = :site AND day BETWEEN :from AND :to AND name = :name AND prop_key = ''", $params + ['name' => $name]))
                        : Types::int($this->connection->fetchOne('SELECT COALESCE(SUM(visits), 0) FROM (' . RawSelects::events(' AND e.local_day BETWEEN :from AND :to AND e.name = :name') . ') parts', $params + ['name' => $name]));
                } else {
                    $key = (string) array_key_first($props);
                    $expected = $props[$key];
                    $expected = \is_scalar($expected) ? (string) $expected : '';
                    $count = $useRollup
                        ? Types::int($this->connection->fetchOne('SELECT COALESCE(SUM(visits), 0) FROM rollup_events_daily WHERE site_id = :site AND day BETWEEN :from AND :to AND name = :name AND prop_key = :key AND prop_value = :value', $params + ['name' => $name, 'key' => $key, 'value' => $expected]))
                        : Types::int($this->connection->fetchOne('SELECT COALESCE(SUM(visits), 0) FROM (' . RawSelects::eventProps(' AND e.local_day BETWEEN :from AND :to AND e.name = :name') . ') parts WHERE prop_key = :key AND prop_value = :value', $params + ['name' => $name, 'key' => $key, 'value' => $expected]));
                }
            } else {
                $name = \is_string($match['name'] ?? null) ? $match['name'] : '';
                $row = $this->connection->fetchAssociative(
                    'SELECT COUNT(*) AS c, COALESCE(SUM(IF(currency = :currency, value_minor, 0)), 0) AS v FROM conversions WHERE site_id = :site AND local_day BETWEEN :from AND :to AND name = :name',
                    $params + ['name' => $name, 'currency' => $site->currency],
                );
                $count = Types::int(\is_array($row) ? $row['c'] : 0);
                $value = Types::int(\is_array($row) ? $row['v'] : 0);
            }

            $rows[] = [
                'goal_id' => $goal->id(),
                'name' => $goal->name,
                'type' => $goal->type->value,
                'conversions' => $count,
                'conversion_rate' => $visits > 0 ? round($count / $visits, 4) : null,
                'value_minor' => $value,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => Types::int($b['conversions']) <=> Types::int($a['conversions']));

        return ['rows' => $rows, 'total_rows' => \count($rows)];
    }
}
