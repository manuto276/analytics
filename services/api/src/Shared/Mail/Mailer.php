<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

interface Mailer
{
    public function isEnabled(): bool;

    /**
     * Sends the message now. A disabled mailer drops it silently; a failing transport throws
     * (Symfony\Component\Mailer\Exception\TransportExceptionInterface).
     */
    public function send(MailMessage $message): void;
}
