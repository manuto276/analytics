<?php

declare(strict_types=1);

namespace Analytics\Tracking\Domain;

enum TrackingLevel: string
{
    /** No consent: no visitor id, no cookies. */
    case Base = 'b';
    /** After consent: visitor id and session id from first-party cookies. */
    case Consented = 'c';
}
