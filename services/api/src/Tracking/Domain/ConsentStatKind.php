<?php

declare(strict_types=1);

namespace Analytics\Tracking\Domain;

enum ConsentStatKind: string
{
    case Shown = 'shown';
    case Accept = 'accept';
    case Reject = 'reject';
    case Dismiss = 'dismiss';
    case Reopen = 'reopen';

    public function column(): string
    {
        return match ($this) {
            self::Shown => 'shown',
            self::Accept => 'accepted',
            self::Reject => 'rejected',
            self::Dismiss => 'dismissed',
            self::Reopen => 'reopened',
        };
    }
}
