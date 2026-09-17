<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\Filter;
use Analytics\Reporting\Domain\FilterOperator;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Sites\Domain\SiteSnapshot;
use Psr\Clock\ClockInterface;

/**
 * Builds a ReportQuery from query parameters (period, range, interval, compare, filters, paging).
 */
final readonly class ReportQueryFactory
{
    public const int MAX_LIMIT = 1000;

    public function __construct(private ClockInterface $clock) {}

    /**
     * @param array<array-key, mixed> $params
     * @param array<string, string>   $options
     */
    public function create(SiteSnapshot $site, array $params, array $options = [], bool $forceRaw = false): ReportQuery
    {
        $today = $this->clock->now()->setTimezone($site->timezone())->setTime(0, 0);
        $period = \is_string($params['period'] ?? null) ? $params['period'] : '30d';
        if (!\in_array($period, DateRange::PERIODS, true)) {
            throw ApiProblem::validation(['period' => ['Must be one of: ' . implode(', ', DateRange::PERIODS) . '.']]);
        }
        $from = \is_string($params['from'] ?? null) ? $params['from'] : null;
        $to = \is_string($params['to'] ?? null) ? $params['to'] : null;
        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw ApiProblem::validation([$name => ['Must be a date (YYYY-MM-DD).']]);
            }
        }
        try {
            $range = DateRange::fromPeriod($period, $today, $from, $to);
        } catch (\InvalidArgumentException $e) {
            throw ApiProblem::validation(['period' => [$e->getMessage()]]);
        }
        if ($range->days() > DateRange::MAX_DAYS) {
            throw ApiProblem::validation(['from' => ['The range is too long.']]);
        }

        $interval = ReportService::resolveInterval(\is_string($params['interval'] ?? null) ? $params['interval'] : null, $range);
        $comparison = ReportService::comparison(\is_string($params['compare'] ?? null) ? $params['compare'] : null);

        $limit = isset($params['limit']) && is_numeric($params['limit']) ? (int) $params['limit'] : 50;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw ApiProblem::validation(['limit' => ['Must be between 1 and ' . self::MAX_LIMIT . '.']]);
        }
        $offset = Cursor::decode(\is_string($params['cursor'] ?? null) ? $params['cursor'] : null);

        $sort = \is_string($params['sort'] ?? null) ? $params['sort'] : null;
        $descending = true;
        if ($sort !== null) {
            $descending = !str_starts_with($sort, '+');
            $sort = ltrim($sort, '+-');
            if (str_starts_with((string) ($params['sort'] ?? ''), '-')) {
                $descending = true;
            } elseif (str_starts_with((string) ($params['sort'] ?? ''), '+')) {
                $descending = false;
            }
            if (preg_match('/^[a-z_]{1,32}$/', $sort) !== 1) {
                throw ApiProblem::validation(['sort' => ['Invalid sort.']]);
            }
        }

        return new ReportQuery(
            site: $site,
            range: $range,
            compareRange: $range->compareRange($comparison),
            comparison: $comparison,
            interval: $interval,
            filters: self::filters($params),
            limit: $limit,
            offset: $offset,
            sort: $sort,
            sortDescending: $descending,
            options: $options,
            forceRaw: $forceRaw,
        );
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<Filter>
     */
    public static function filters(array $params): array
    {
        $raw = $params['filter'] ?? [];
        if (!\is_array($raw)) {
            throw ApiProblem::validation(['filter' => ['Use filter[dimension][operator]=value.']]);
        }
        $filters = [];
        foreach ($raw as $dimension => $operators) {
            $dimension = (string) $dimension;
            if (!\in_array($dimension, Filter::DIMENSIONS, true)) {
                throw ApiProblem::validation(['filter' => ['Unknown dimension ' . $dimension . '.']]);
            }
            if (!\is_array($operators)) {
                throw ApiProblem::validation(['filter' => ['Use filter[' . $dimension . '][is]=value.']]);
            }
            foreach ($operators as $operator => $value) {
                $op = FilterOperator::tryFrom((string) $operator);
                if ($op === null) {
                    throw ApiProblem::validation(['filter' => ['Unknown operator ' . $operator . '. Use is, is_not, contains, prefix or glob.']]);
                }
                if (!\is_string($value) || mb_strlen($value) > 1024) {
                    throw ApiProblem::validation(['filter' => ['Filter values must be strings of at most 1024 characters.']]);
                }
                $filters[] = new Filter($dimension, $op, $value);
            }
        }
        if (\count($filters) > 10) {
            throw ApiProblem::validation(['filter' => ['At most 10 filters.']]);
        }

        return $filters;
    }
}
