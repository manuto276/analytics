<?php

declare(strict_types=1);

namespace Analytics\Shared\Mail;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends through the transport described by MAILER_DSN (smtp://, smtps://, sendmail://, null://).
 * The transport is built on first use, so a bad DSN only fails the request that sends mail;
 * `app:preflight` and `mail:test` report it up front.
 */
final class SymfonyMailer implements Mailer
{
    private ?\Symfony\Component\Mailer\Mailer $mailer = null;

    public function __construct(
        private readonly ?string $dsn,
        private readonly string $from,
        private readonly string $fromName = '',
    ) {}

    public function isEnabled(): bool
    {
        return $this->dsn !== null && $this->dsn !== '';
    }

    public function send(MailMessage $message): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $this->mailer ??= new \Symfony\Component\Mailer\Mailer(Transport::fromDsn((string) $this->dsn));
        $email = new Email()
            ->from(new Address($this->from, $this->fromName))
            ->to($message->to)
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);
        // Plain, predictable headers: no tracking, no read receipts, no list headers.
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $this->mailer->send($email);
    }

    /** Why the DSN cannot be used (unparsable, unsupported scheme), or null when it can. */
    public static function dsnProblem(string $dsn): ?string
    {
        try {
            Transport::fromDsn($dsn);

            return null;
        } catch (\Throwable $e) {
            // Symfony's messages quote the scheme or the DSN shape, never the password.
            return $e->getMessage();
        }
    }

    /** The DSN without credentials ("smtp://smtp.example.com:587"), safe to print and log. */
    public static function describeDsn(string $dsn): string
    {
        try {
            $parsed = Dsn::fromString($dsn);
        } catch (\Throwable) {
            return '(unparsable)';
        }
        $port = $parsed->getPort();

        return $parsed->getScheme() . '://' . $parsed->getHost() . ($port !== null ? ':' . $port : '');
    }
}
