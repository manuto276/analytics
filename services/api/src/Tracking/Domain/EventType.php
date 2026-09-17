<?php

declare(strict_types=1);

namespace Analytics\Tracking\Domain;

enum EventType: string
{
    case Pageview = 'pv';
    case Custom = 'ev';
    case Engagement = 'en';
    case ConsentUpgrade = 'cu';
    case ConsentStat = 'cs';
}
