<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

interface Mailer
{
    public function isEnabled(): bool;

    public function send(string $to, string $subject, string $text): void;
}
