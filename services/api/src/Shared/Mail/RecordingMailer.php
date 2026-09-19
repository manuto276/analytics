<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

/**
 * In-memory mailer used in tests.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, text: string, html: string}> */
    public array $sent = [];

    /** $enabled is public so a test can switch the mailer off and on again. */
    public function __construct(public bool $enabled = true) {}

    public function reset(): void
    {
        $this->sent = [];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function send(MailMessage $message): void
    {
        $this->sent[] = ['to' => $message->to, 'subject' => $message->subject, 'text' => $message->text, 'html' => $message->html];
    }
}
