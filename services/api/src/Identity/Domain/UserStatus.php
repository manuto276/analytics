<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

enum UserStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
