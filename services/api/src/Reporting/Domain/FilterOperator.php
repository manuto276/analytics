<?php

declare(strict_types=1);

namespace Analytics\Reporting\Domain;

enum FilterOperator: string
{
    case Is = 'is';
    case IsNot = 'is_not';
    case Contains = 'contains';
    case Prefix = 'prefix';
    case Glob = 'glob';
}
