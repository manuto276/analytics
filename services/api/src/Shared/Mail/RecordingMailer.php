<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

/**
 * In-memory mailer used in tests.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, text: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $enabled = true) {}

    public function reset(): void
    {
        $this->sent = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function send(string $to, string $subject, string $text): void
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
    }
}
