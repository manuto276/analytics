<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

enum Comparison: string
{
    case None = 'none';
    case PreviousPeriod = 'previous_period';
    case PreviousYear = 'previous_year';
}
