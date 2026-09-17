<?php

declare(strict_types=1);

namespace Analytics\Conversions\Domain;

enum GoalType: string
{
    /** match: {"path": "/pricing*"} (glob on path) */
    case Pageview = 'pageview';
    /** match: {"name": "signup_click", "props": {"plan": "pro"}} */
    case Event = 'event';
    /** match: {"name": "purchase"} (server-side conversions) */
    case Conversion = 'conversion';
}
