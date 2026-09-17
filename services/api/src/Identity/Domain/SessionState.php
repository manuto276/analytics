<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

enum SessionState: string
{
    case PendingMfa = 'pending_mfa';
    case Active = 'active';
}
