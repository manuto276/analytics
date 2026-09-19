<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Shared\Mail\MailLayout;
use Analytics\Shared\Mail\SymfonyMailer;
use PHPUnit\Framework\TestCase;

final class MailLayoutTest extends TestCase
{
    public function testRendersTextAndEscapedSelfContainedHtml(): void
    {
        $message = MailLayout::render(
            to: 'a@example.com',
            subject: 'Hello <world>',
            locale: 'en',
            heading: 'Heading & more',
            paragraphs: ['First "paragraph".', '<script>alert(1)</script>'],
            action: ['label' => 'Do it', 'url' => 'https://analytics.test/x?token=abc&y=1'],
            actionFallback: 'Copy this link:',
            after: ['Last words.'],
            footer: 'Footer.',
        );

        self::assertSame('a@example.com', $message->to);
        self::assertSame('Hello <world>', $message->subject);
        self::assertStringContainsString("Heading & more\n\nFirst \"paragraph\".", $message->text);
        self::assertStringContainsString("Copy this link:\nhttps://analytics.test/x?token=abc&y=1", $message->text);
        self::assertStringEndsWith("-- \nFooter.\n", $message->text);

        self::assertStringContainsString('<html lang="en">', $message->html);
        self::assertStringContainsString('&lt;script&gt;', $message->html);
        self::assertStringNotContainsString('<script>', $message->html);
        self::assertStringContainsString('href="https://analytics.test/x?token=abc&amp;y=1"', $message->html);
        self::assertStringContainsString('<title>Hello &lt;world&gt;</title>', $message->html);
        // Nothing is loaded from anywhere: no images, no external stylesheets or fonts.
        self::assertDoesNotMatchRegularExpression('/<img|<link|url\(|@import|src=/i', $message->html);
    }

    public function testDsnHelpers(): void
    {
        self::assertNull(SymfonyMailer::dsnProblem('smtp://user:p%40ss@smtp.example.com:587'));
        self::assertNull(SymfonyMailer::dsnProblem('null://null'));
        self::assertNotNull(SymfonyMailer::dsnProblem('not a dsn'));
        self::assertNotNull(SymfonyMailer::dsnProblem('carrier-pigeon://coop'));
        self::assertSame('smtp://smtp.example.com:587', SymfonyMailer::describeDsn('smtp://user:secret@smtp.example.com:587'));
        self::assertSame('smtps://smtp.example.com', SymfonyMailer::describeDsn('smtps://user:secret@smtp.example.com'));
        self::assertSame('(unparsable)', SymfonyMailer::describeDsn('nope'));
    }
}
