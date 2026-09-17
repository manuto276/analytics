<?php

declare(strict_types=1);

namespace Analytics\Sites\Domain;

enum VisitorHashMode: string
{
    /** Daily-rotating salted hash of (site, shortened IP, UA): visitor-days, visits, bounce and duration. */
    case DailyHash = 'daily_hash';
    /** No visitor hash at all: pageviews and events only; visits estimated from entry pageviews. */
    case PageviewsOnly = 'pageviews_only';
}
