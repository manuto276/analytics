<?php

declare(strict_types=1);

namespace Analytics\Sites\Domain;

enum DntMode: string
{
    case Ignore = 'ignore';
    /** DNT=1 behaves like a rejection: base level only, no banner. */
    case NoCookie = 'no_cookie';
    /** DNT=1 sends nothing. */
    case NoTracking = 'no_tracking';
}
