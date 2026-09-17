<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

enum Interval: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
