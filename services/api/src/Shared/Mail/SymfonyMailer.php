<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class SymfonyMailer implements Mailer
{
    private ?\Symfony\Component\Mailer\Mailer $mailer = null;

    public function __construct(private readonly ?string $dsn, private readonly string $from) {}

    public function isEnabled(): bool
    {
        return $this->dsn !== null && $this->dsn !== '';
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->mailer ??= new \Symfony\Component\Mailer\Mailer(Transport::fromDsn((string) $this->dsn));
        $this->mailer->send(new Email()->from($this->from)->to($to)->subject($subject)->text($text));
    }
}
