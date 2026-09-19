<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

/**
 * One outgoing message: always multipart, a plain-text body and an HTML alternative of the same text.
 */
final readonly class MailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $text,
        public string $html,
    ) {}
}
