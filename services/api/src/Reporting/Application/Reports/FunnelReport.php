<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Conversions\Domain\Funnel;
use Analytics\Conversions\Domain\Goal;
use Analytics\Conversions\Domain\GoalType;
use Analytics\Reporting\Application\SqlFilters;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Funnel completion: each step must happen after the previous one, inside one visit (scope=visit)
 * or inside the visitor's window (scope=visitor, consented visitors only).
 */
final readonly class FunnelReport
{
    public const int MAX_SUBJECTS = 200_000;

    public function __construct(private Connection $connection, private EntityManagerInterface $em) {}

    /**
     * @return array<string, mixed>
     */
    public function run(SiteSnapshot $site, int $funnelId, DateRange $range, string $breakdown): array
    {
        $funnel = $this->em->find(Funnel::class, $funnelId);
        if (!$funnel instanceof Funnel || $funnel->siteId !== $site->id) {
            throw ApiProblem::notFound('Funnel not found.');
        }
        $steps = [];
        foreach ($funnel->steps as $step) {
            $goal = $this->em->find(Goal::class, $step->goalId);
            if (!$goal instanceof Goal) {
                continue;
            }
            $steps[] = ['position' => $step->position, 'goal' => $goal];
        }
        if (\count($steps) < 2) {
            throw ApiProblem::conflict('funnel_incomplete', 'This funnel has fewer than two usable steps.');
        }

        $byVisitor = $funnel->scope === 'visitor';
        $subjects = [];
        foreach ($steps as $index => $step) {
            foreach ($this->occurrences($site, $step['goal'], $range, $byVisitor, $funnel->windowDays) as $subject => $timestamp) {
                $subjects[$subject][$index] = $timestamp;
            }
        }
        if (\count($subjects) > self::MAX_SUBJECTS) {
            throw new ApiProblem(422, 'range_too_large', 'Unprocessable entity', 'Too many visits in this range for a funnel; shorten the period.');
        }

        $groups = $breakdown === 'none' ? [] : $this->breakdownValues($site, $range, $breakdown, $byVisitor);
        $counts = array_fill(0, \count($steps), 0);
        $groupCounts = [];

        foreach ($subjects as $subject => $timestamps) {
            $previous = null;
            $reached = 0;
            foreach (array_keys($steps) as $index) {
                $timestamp = $timestamps[$index] ?? null;
                if ($timestamp === null || ($previous !== null && $timestamp < $previous)) {
                    break;
                }
                $previous = $timestamp;
                ++$reached;
            }
            for ($index = 0; $index < $reached; ++$index) {
                ++$counts[$index];
                if ($groups !== []) {
                    $value = $groups[$subject] ?? 'unknown';
                    $groupCounts[$value][$index] = ($groupCounts[$value][$index] ?? 0) + 1;
                }
            }
        }

        $entered = $counts[0];
        $rows = [];
        foreach ($steps as $index => $step) {
            $count = $counts[$index];
            $rows[] = [
                'position' => $step['position'],
                'goal_id' => $step['goal']->id(),
                'name' => $step['goal']->name,
                'count' => $count,
                'conversion_rate' => $entered > 0 ? round($count / $entered, 4) : null,
                'drop_off' => $index === 0 ? 0 : $counts[$index - 1] - $count,
            ];
        }

        $breakdownRows = [];
        foreach ($groupCounts as $value => $stepCounts) {
            $series = [];
            foreach (array_keys($steps) as $index) {
                $series[] = $stepCounts[$index] ?? 0;
            }
            $breakdownRows[] = ['value' => (string) $value, 'steps' => $series];
        }
        usort($breakdownRows, static fn(array $a, array $b): int => ($b['steps'][0] ?? 0) <=> ($a['steps'][0] ?? 0));

        return [
            'funnel' => ['id' => $funnel->id(), 'name' => $funnel->name, 'scope' => $funnel->scope, 'window_days' => $funnel->windowDays, 'steps' => array_map(static fn(array $s): array => ['position' => $s['position'], 'goal_id' => $s['goal']->id(), 'goal_name' => $s['goal']->name], $steps)],
            'entered' => $entered,
            'completed' => $counts[\count($steps) - 1],
            'conversion_rate' => $entered > 0 ? round($counts[\count($steps) - 1] / $entered, 4) : null,
            'steps' => $rows,
            'breakdown' => $breakdownRows,
        ];
    }

    /**
     * Earliest time each subject (visit id or visitor id) matched a goal.
     *
     * @return array<string, string>
     */
    private function occurrences(SiteSnapshot $site, Goal $goal, DateRange $range, bool $byVisitor, int $windowDays): array
    {
        $params = ['site' => $site->id, 'from' => $range->fromDay(), 'to' => $range->toDay()];
        $subject = $byVisitor ? 'HEX(e.visitor_id)' : 'CAST(e.visit_id AS CHAR)';
        $filter = 'e.visit_id IS NOT NULL';
        if ($byVisitor) {
            $filter = 'e.visitor_id IS NOT NULL';
        }

        if ($goal->type === GoalType::Conversion) {
            $name = \is_string($goal->match['name'] ?? null) ? $goal->match['name'] : '';
            $rows = $this->connection->fetchAllAssociative(
                'SELECT HEX(c.visitor_id) AS subject, MIN(c.occurred_at) AS at FROM conversions c
                  WHERE c.site_id = :site AND c.local_day BETWEEN :from AND :to AND c.name = :name AND c.visitor_id IS NOT NULL
                  GROUP BY c.visitor_id',
                $params + ['name' => $name],
            );
            if (!$byVisitor) {
                // Map the conversion to the visit of the same visitor that was open when it happened.
                $visits = $this->connection->fetchAllAssociative(
                    'SELECT HEX(v.visitor_id) AS visitor, CAST(v.id AS CHAR) AS visit_id, v.started_at FROM visits v
                      WHERE v.site_id = :site AND v.local_day BETWEEN :from AND :to AND v.visitor_id IS NOT NULL',
                    $params,
                );
                $byVisitorId = [];
                foreach ($visits as $visit) {
                    $byVisitorId[Types::string($visit['visitor'])][] = $visit;
                }
                $mapped = [];
                foreach ($rows as $row) {
                    foreach ($byVisitorId[Types::string($row['subject'])] ?? [] as $visit) {
                        if (Types::string($visit['started_at']) <= Types::string($row['at'])) {
                            $mapped[Types::string($visit['visit_id'])] = Types::string($row['at']);
                        }
                    }
                }

                return $mapped;
            }

            return self::index($rows);
        }

        if ($goal->type === GoalType::Pageview) {
            $path = \is_string($goal->match['path'] ?? null) ? $goal->match['path'] : '';
            $rows = $this->connection->fetchAllAssociative(
                'SELECT ' . $subject . ' AS subject, MIN(e.occurred_at) AS at FROM events_raw e
                  WHERE e.site_id = :site AND e.local_day BETWEEN :from AND :to AND e.type = \'pv\' AND e.path LIKE :path AND ' . $filter . '
                  GROUP BY subject',
                $params + ['path' => SqlFilters::globToLike($path)],
            );

            return self::index($rows);
        }

        $name = \is_string($goal->match['name'] ?? null) ? $goal->match['name'] : '';
        $props = \is_array($goal->match['props'] ?? null) ? $goal->match['props'] : [];
        $propCondition = '';
        if ($props !== []) {
            $key = (string) array_key_first($props);
            $value = $props[$key];
            $params['prop_value'] = \is_scalar($value) ? (string) $value : '';
            $params['prop_path'] = '$."' . str_replace('"', '', $key) . '"';
            $propCondition = ' AND JSON_UNQUOTE(JSON_EXTRACT(e.props, :prop_path)) = :prop_value';
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $subject . ' AS subject, MIN(e.occurred_at) AS at FROM events_raw e
              WHERE e.site_id = :site AND e.local_day BETWEEN :from AND :to AND e.type = \'ev\' AND e.name = :name' . $propCondition . ' AND ' . $filter . '
              GROUP BY subject',
            $params + ['name' => $name],
        );

        return self::index($rows);
    }

    /** @return array<string, string> subject => value */
    private function breakdownValues(SiteSnapshot $site, DateRange $range, string $breakdown, bool $byVisitor): array
    {
        $column = match ($breakdown) {
            'channel' => 'v.channel',
            'device' => "IFNULL(v.device, 'unknown')",
            default => throw ApiProblem::validation(['breakdown' => ['Must be none, channel or device.']]),
        };
        $subject = $byVisitor ? 'HEX(v.visitor_id)' : 'CAST(v.id AS CHAR)';
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . $subject . ' AS subject, ' . $column . ' AS value FROM visits v
              WHERE v.site_id = ? AND v.local_day BETWEEN ? AND ?' . ($byVisitor ? ' AND v.visitor_id IS NOT NULL' : ''),
            [$site->id, $range->fromDay(), $range->toDay()],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        );
        $values = [];
        foreach ($rows as $row) {
            $values[Types::string($row['subject'])] = Types::string($row['value']);
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, string>
     */
    private static function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[Types::string($row['subject'])] = Types::string($row['at']);
        }

        return $indexed;
    }
}
