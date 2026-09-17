<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

use Analytics\Identity\Domain\AuthSession;
use Analytics\Identity\Domain\User;

final readonly class LoginResult
{
    public function __construct(
        public User $user,
        public AuthSession $session,
        public string $token,
        public bool $mfaRequired,
    ) {}
}
