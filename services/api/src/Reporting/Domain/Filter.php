<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

final readonly class Filter
{
    /** Dimensions that live on the visit row. */
    public const array VISIT_DIMENSIONS = ['entry_page', 'exit_page', 'host', 'channel', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'device', 'browser', 'os', 'country', 'level'];
    /** Dimensions that live on the event row. */
    public const array EVENT_DIMENSIONS = ['page', 'event', 'content'];
    public const array DIMENSIONS = [...self::VISIT_DIMENSIONS, ...self::EVENT_DIMENSIONS];

    public function __construct(
        public string $dimension,
        public FilterOperator $operator,
        public string $value,
    ) {}

    public function isVisitDimension(): bool
    {
        return \in_array($this->dimension, self::VISIT_DIMENSIONS, true);
    }
}
