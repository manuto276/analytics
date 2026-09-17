<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Application\Reports\AttributionReport;
use Analytics\Reporting\Application\Reports\CohortsReport;
use Analytics\Reporting\Application\Reports\ConsentReport;
use Analytics\Reporting\Application\Reports\FunnelReport;
use Analytics\Reporting\Application\Reports\GoalsReport;
use Analytics\Reporting\Application\Reports\RealtimeReport;
use Analytics\Reporting\Application\Reports\TableReports;
use Analytics\Reporting\Application\Reports\TimeseriesReport;
use Analytics\Reporting\Domain\Comparison;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Interval;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\VisitorHashMode;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Runs a report: plans the source, computes it (cached) and builds the response envelope.
 */
final readonly class ReportService
{
    public const array TABLE_REPORTS = ['pages', 'landing-pages', 'sources', 'campaigns', 'tech', 'countries', 'events', 'event-props', 'content', 'conversions'];

    public function __construct(
        private QueryPlanner $planner,
        private DailyMetrics $metrics,
        private TableReports $tables,
        private TimeseriesReport $timeseries,
        private RealtimeReport $realtime,
        private GoalsReport $goals,
        private FunnelReport $funnels,
        private AttributionReport $attribution,
        private ConsentReport $consent,
        private CohortsReport $cohorts,
        private ReportCache $cache,
        private Connection $connection,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(string $report, ReportQuery $query): array
    {
        $site = $query->site;
        $rollupVersion = Types::int($this->connection->fetchOne('SELECT rollup_version FROM sites WHERE id = ?', [$site->id]));
        $plan = $report === 'realtime' ? ['source' => 'raw', 'interval' => $query->interval] : $this->planner->plan($report, $query);
        $useRollup = $plan['source'] === 'rollup';

        $cached = $this->cache->remember($report, $query, $rollupVersion, fn(): array => $this->compute($report, $query, $useRollup));
        $data = $cached['data'];

        $sessions = $site->visitorHashMode === VisitorHashMode::DailyHash;
        $availability = [
            'visitors' => $sessions || $site->cookieLevelEnabled,
            'bounce' => $sessions,
            'duration' => $sessions,
        ];
        $nextCursor = null;
        if (isset($data['total_rows']) && \is_int($data['total_rows']) && $query->offset + $query->limit < $data['total_rows']) {
            $nextCursor = Cursor::encode($query->offset + $query->limit);
        }

        return [
            'data' => $data,
            'meta' => [
                'site_id' => $site->id,
                'range' => $query->range->toArray(),
                'compare_range' => $query->compareRange?->toArray(),
                'interval' => \in_array($report, ['overview', 'timeseries'], true) ? $query->interval->value : null,
                'source' => $plan['source'],
                'availability' => $availability,
                'generated_at' => $this->clock->now()->format(\DATE_ATOM),
                'cache' => $cached['cache'],
                'timezone' => $site->timezone,
                'currency' => $site->currency,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function compute(string $report, ReportQuery $query, bool $useRollup): array
    {
        $site = $query->site;
        $sessions = $site->visitorHashMode === VisitorHashMode::DailyHash;
        $visitorsAvailable = $sessions || $site->cookieLevelEnabled;

        if (\in_array($report, self::TABLE_REPORTS, true)) {
            $result = $this->tables->run($report, $query, $query->range, $useRollup);
            if ($query->compareRange !== null) {
                $compare = $this->tables->run($report, $query->withRange($query->compareRange), $query->compareRange, $useRollup);
                $result['compare_total_rows'] = $compare['total_rows'];
            }

            return $result;
        }

        return match ($report) {
            'overview' => $this->overview($query, $useRollup, $visitorsAvailable, $sessions),
            'timeseries' => [
                'points' => $this->timeseries->points($query, $query->range, $useRollup, $visitorsAvailable, $sessions, $sessions),
                'compare_points' => $query->compareRange === null ? null : $this->timeseries->points($query->withRange($query->compareRange), $query->compareRange, $useRollup, $visitorsAvailable, $sessions, $sessions),
            ],
            'realtime' => $this->realtime->run($site),
            'goals' => $this->goals->run($site, $query->range, $useRollup),
            'funnels' => $this->funnels->run($site, (int) $query->option('funnel'), $query->range, $query->option('breakdown', 'none')),
            'attribution' => $this->attribution->run(
                $site,
                $query->range,
                $query->option('model', 'first_touch'),
                $query->option('group', 'channel'),
                (int) $query->option('window', '30'),
                $query->option('base') === '' ? null : $query->option('base'),
                $query->option('target') === '' ? null : $query->option('target'),
            ),
            'consent' => $this->consent->run($site, $query->range),
            'cohorts' => $this->cohorts->run($site, $query->option('cohort', 'month'), max(1, min(13, (int) $query->option('periods', '6')))),
            default => throw \Analytics\Shared\Http\ApiProblem::notFound('Unknown report ' . $report),
        };
    }

    /** @return array<string, mixed> */
    private function overview(ReportQuery $query, bool $useRollup, bool $visitorsAvailable, bool $sessions): array
    {
        $current = DailyMetrics::sum($useRollup ? $this->metrics->rollup($query->site, $query->range) : $this->metrics->raw($query->site, $query->range, $query->filters));
        $metrics = DailyMetrics::present($current, $visitorsAvailable, $sessions, $sessions);

        $compare = null;
        $deltas = [];
        if ($query->compareRange !== null) {
            $previous = DailyMetrics::sum($useRollup ? $this->metrics->rollup($query->site, $query->compareRange) : $this->metrics->raw($query->site, $query->compareRange, $query->filters));
            $compare = DailyMetrics::present($previous, $visitorsAvailable, $sessions, $sessions);
            foreach ($metrics as $name => $value) {
                $before = $compare[$name] ?? null;
                $deltas[$name] = is_numeric($value) && is_numeric($before) && (float) $before !== 0.0
                    ? round(((float) $value - (float) $before) / (float) $before, 4)
                    : null;
            }
        }

        return ['metrics' => $metrics, 'compare' => $compare, 'deltas' => (object) $deltas];
    }

    /** Default interval for a range, honouring the hour limit. */
    public static function resolveInterval(?string $requested, DateRange $range): Interval
    {
        if ($requested === null) {
            return $range->defaultInterval();
        }
        $interval = Interval::tryFrom($requested) ?? throw \Analytics\Shared\Http\ApiProblem::validation(['interval' => ['Must be hour, day, week or month.']]);
        if ($interval === Interval::Hour) {
            QueryPlanner::assertHourlyRangeIsShort($range);
        }

        return $interval;
    }

    public static function comparison(?string $requested): Comparison
    {
        return Comparison::tryFrom($requested ?? 'none') ?? throw \Analytics\Shared\Http\ApiProblem::validation(['compare' => ['Must be none, previous_period or previous_year.']]);
    }
}
