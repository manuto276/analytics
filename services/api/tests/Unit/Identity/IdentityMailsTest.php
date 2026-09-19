<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Identity;

use Analytics\Identity\Application\IdentityMails;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class IdentityMailsTest extends TestCase
{
    private const string TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ';

    private static function user(string $locale): User
    {
        $user = new User('mario@example.com', 'hash', 'Mario', GlobalRole::Member, new \DateTimeImmutable('2026-09-19 08:00:00'));
        $user->locale = $locale;

        return $user;
    }

    public function testPasswordResetIsLocalised(): void
    {
        $mails = new IdentityMails('https://stats.example.net');
        $en = $mails->passwordReset(self::user('en'), self::TOKEN);
        $it = $mails->passwordReset(self::user('it'), self::TOKEN);
        $fallback = $mails->passwordReset(self::user('de'), self::TOKEN);

        self::assertSame('mario@example.com', $en->to);
        self::assertSame('Reset your password on stats.example.net', $en->subject);
        self::assertSame('Reimposta la password su stats.example.net', $it->subject);
        self::assertSame($en->subject, $fallback->subject, 'unknown locales fall back to English');
        foreach ([$en, $it] as $message) {
            self::assertStringContainsString('https://stats.example.net/password/reset/' . self::TOKEN, $message->text);
            self::assertStringContainsString('href="https://stats.example.net/password/reset/' . self::TOKEN . '"', $message->html);
        }
        self::assertStringContainsString('<html lang="it">', $it->html);
    }

    public function testEmailChangeMessages(): void
    {
        $mails = new IdentityMails('https://stats.example.net');
        $user = self::user('it');

        $confirm = $mails->emailChangeConfirmation($user, 'nuovo@example.com', self::TOKEN);
        self::assertSame('nuovo@example.com', $confirm->to);
        self::assertStringContainsString('https://stats.example.net/account/email/confirm?token=' . self::TOKEN, $confirm->text);
        self::assertStringNotContainsString('mario@example.com', $confirm->text, 'the new mailbox does not learn the current address');

        $user->email = 'nuovo@example.com';
        $notice = $mails->emailChanged($user, 'mario@example.com', new \DateTimeImmutable('2026-09-19 10:30:00', new \DateTimeZone('Europe/Rome')));
        self::assertSame('mario@example.com', $notice->to);
        self::assertStringContainsString('Il 2026-09-19 08:30 (UTC)', $notice->text);
        self::assertStringContainsString('da mario@example.com a nuovo@example.com', $notice->text);
        self::assertStringNotContainsString('href=', $notice->html, 'the notice carries no link to click');
    }
}
