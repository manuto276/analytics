<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Filter;
use Analytics\Reporting\Domain\Interval;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Http\ApiProblem;
use Psr\Clock\ClockInterface;

/**
 * Decides whether a report is answered from rollups or from raw data, and refuses combinations
 * that raw data can no longer answer (beyond the retention window).
 */
final readonly class QueryPlanner
{
    /** Filter dimensions each report's rollup can answer. */
    public const array ROLLUP_DIMENSIONS = [
        'overview' => [],
        'timeseries' => [],
        'pages' => ['page', 'host'],
        'landing-pages' => ['entry_page', 'page', 'host', 'channel'],
        'sources' => ['channel', 'source', 'referrer'],
        'campaigns' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'],
        'tech' => ['device', 'browser', 'os'],
        'countries' => ['country'],
        'events' => ['event'],
        'event-props' => ['event'],
        'content' => ['content', 'channel'],
        'conversions' => [],
        'goals' => [],
        'consent' => [],
        'cohorts' => [],
        'funnels' => [],
        'attribution' => [],
    ];

    /** Reports that cannot be filtered at all. */
    public const array UNFILTERABLE = ['conversions', 'goals', 'consent', 'cohorts', 'realtime', 'funnels', 'attribution'];

    public function __construct(private ClockInterface $clock, private int $retentionMonths) {}

    /** @return array{source: string, interval: Interval} */
    public function plan(string $report, ReportQuery $query): array
    {
        $dimensions = $query->filterDimensions();
        foreach ($dimensions as $dimension) {
            if (!\in_array($dimension, Filter::DIMENSIONS, true)) {
                throw ApiProblem::validation(['filter' => ['Unknown filter dimension ' . $dimension . '.']]);
            }
        }
        if ($dimensions !== [] && \in_array($report, self::UNFILTERABLE, true)) {
            throw new ApiProblem(422, 'filter_unsupported', 'Unprocessable entity', 'The ' . $report . ' report cannot be filtered.');
        }
        if ($report === 'tech' && $dimensions !== [] && $dimensions !== [$query->option('group', 'device')]) {
            return $this->rawPlan($report, $query);
        }

        $rollupDimensions = self::ROLLUP_DIMENSIONS[$report] ?? [];
        $covered = array_diff($dimensions, $rollupDimensions) === [];
        if (!$query->forceRaw && $covered && $query->interval !== Interval::Hour) {
            return ['source' => 'rollup', 'interval' => $query->interval];
        }

        return $this->rawPlan($report, $query);
    }

    /** @return array{source: string, interval: Interval} */
    private function rawPlan(string $report, ReportQuery $query): array
    {
        $cutoff = $this->clock->now()->setTimezone($query->site->timezone())->modify('-' . $this->retentionMonths . ' months');
        if ($query->range->from < $cutoff->setTime(0, 0)) {
            throw new ApiProblem(
                422,
                'filter_unavailable_for_range',
                'Unprocessable entity',
                \sprintf('Raw data older than %d months is removed by retention; remove the filters or shorten the range.', $this->retentionMonths),
            );
        }

        return ['source' => 'raw', 'interval' => $query->interval];
    }

    /** Hourly data is only available from raw events, so the range must be short. */
    public static function assertHourlyRangeIsShort(DateRange $range): void
    {
        if ($range->days() > 2) {
            throw ApiProblem::validation(['interval' => ['The hour interval needs a range of at most 2 days.']]);
        }
    }
}
