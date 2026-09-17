<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

use Analytics\Sites\Domain\SiteSnapshot;

final readonly class ReportQuery
{
    /**
     * @param list<Filter>         $filters
     * @param array<string, mixed> $options report-specific options (kind, group, prefix, model…)
     */
    public function __construct(
        public SiteSnapshot $site,
        public DateRange $range,
        public ?DateRange $compareRange,
        public Comparison $comparison,
        public Interval $interval,
        public array $filters,
        public int $limit,
        public int $offset,
        public ?string $sort,
        public bool $sortDescending,
        public array $options = [],
        public bool $forceRaw = false,
    ) {}

    public function withRange(DateRange $range): self
    {
        return new self($this->site, $range, null, Comparison::None, $this->interval, $this->filters, $this->limit, $this->offset, $this->sort, $this->sortDescending, $this->options, $this->forceRaw);
    }

    public function option(string $key, string $default = ''): string
    {
        $value = $this->options[$key] ?? $default;

        return \is_string($value) ? $value : $default;
    }

    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }

    /** @return list<string> dimensions used by the filters */
    public function filterDimensions(): array
    {
        return array_values(array_unique(array_map(static fn(Filter $f): string => $f->dimension, $this->filters)));
    }

    /** Cache key part identifying everything that changes the result. */
    public function fingerprint(string $report): string
    {
        $filters = array_map(static fn(Filter $f): string => $f->dimension . ':' . $f->operator->value . ':' . $f->value, $this->filters);
        sort($filters);

        return hash('xxh128', implode('|', [
            $report,
            $this->site->id,
            $this->range->fromDay(),
            $this->range->toDay(),
            $this->compareRange?->fromDay() ?? '',
            $this->compareRange?->toDay() ?? '',
            $this->interval->value,
            implode(',', $filters),
            $this->limit,
            $this->offset,
            $this->sort ?? '',
            $this->sortDescending ? 'desc' : 'asc',
            json_encode($this->options, \JSON_THROW_ON_ERROR),
            $this->forceRaw ? 'raw' : '',
        ]));
    }
}
