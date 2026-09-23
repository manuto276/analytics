<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Console;

use Analytics\Kernel\Console\MailTestCommand;
use Analytics\Kernel\Console\PreflightCommand;
use Analytics\Kernel\Settings;
use Analytics\Shared\Mail\Mailer;
use Analytics\Shared\Mail\RecordingMailer;
use Analytics\Shared\Mail\SymfonyMailer;
use Analytics\Tests\Support\ConsoleTestCase;
use Analytics\Tests\Support\TestDatabase;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MailCommandsTest extends ConsoleTestCase
{
    /** @param array<string, string> $env */
    private static function settings(array $env): Settings
    {
        return TestDatabase::settings($env);
    }

    public function testMailTestWithoutAMailer(): void
    {
        $output = $this->console('mail:test', ['--to' => 'ops@example.com'], Command::FAILURE);
        self::assertStringContainsString('No mailer configured', $output);
        $this->console('mail:test', ['--to' => 'not an address'], Command::INVALID);
    }

    public function testMailTestSendsAMultipartMessage(): void
    {
        $mailer = new RecordingMailer(true);
        $tester = new CommandTester(new MailTestCommand($mailer, self::settings(['MAILER_DSN' => 'null://null', 'MAIL_FROM' => 'stats@example.net', 'MAIL_FROM_NAME' => 'Example Stats'])));

        self::assertSame(Command::SUCCESS, $tester->execute(['--to' => 'ops@example.com']));
        self::assertStringContainsString('via null://null from Example Stats <stats@example.net>', $tester->getDisplay());
        self::assertStringContainsString('Sent.', $tester->getDisplay());
        self::assertCount(1, $mailer->sent);
        self::assertSame('ops@example.com', $mailer->sent[0]['to']);
        self::assertNotSame('', $mailer->sent[0]['text']);
        self::assertStringContainsString('<html', $mailer->sent[0]['html']);
    }

    public function testMailTestPrintsTheTransportError(): void
    {
        $settings = self::settings(['MAILER_DSN' => 'smtp://user:secret@127.0.0.1:1']);
        $tester = new CommandTester(new MailTestCommand(new SymfonyMailer($settings->mailerDsn, $settings->mailFrom, $settings->mailFromName), $settings));

        self::assertSame(Command::FAILURE, $tester->execute(['--to' => 'ops@example.com']));
        self::assertStringContainsString('Sending failed:', $tester->getDisplay());
        self::assertStringContainsString('smtp://127.0.0.1:1', $tester->getDisplay());
        self::assertStringNotContainsString('secret', $tester->getDisplay(), 'credentials are never printed');

        $bad = self::settings(['MAILER_DSN' => 'carrier-pigeon://coop']);
        $tester = new CommandTester(new MailTestCommand(new RecordingMailer(true), $bad));
        self::assertSame(Command::FAILURE, $tester->execute(['--to' => 'ops@example.com']));
        self::assertStringContainsString('MAILER_DSN cannot be used', $tester->getDisplay());
    }

    public function testPreflightReportsTheMailerAsAWarning(): void
    {
        $connection = $this->container->get(Connection::class);
        \assert($connection instanceof Connection);
        $cases = [
            'not configured' => [[], false],
            'cannot be used' => [['MAILER_DSN' => 'carrier-pigeon://coop'], false],
            'not a deliverable sender' => [['MAILER_DSN' => 'smtp://smtp.example.com:587'], false],
            'configured: smtp://smtp.example.com:587 as Stats <stats@example.net>' => [['MAILER_DSN' => 'smtp://u:p@smtp.example.com:587', 'MAIL_FROM' => 'stats@example.net', 'MAIL_FROM_NAME' => 'Stats'], true],
        ];
        foreach ($cases as $expected => [$env, $ok]) {
            $tester = new CommandTester(new PreflightCommand(self::settings($env), $connection, $this->service(ScriptBundleBuilder::class)));
            self::assertSame(Command::SUCCESS, $tester->execute(['--skip-db' => true, '--json' => true]), 'the mailer never fails preflight');
            $json = json_decode($tester->getDisplay(), true);
            self::assertIsArray($json);
            $check = array_values(array_filter($json['checks'], static fn(array $c): bool => $c['name'] === 'mailer'))[0];
            self::assertSame($ok, $check['ok'], $expected);
            self::assertFalse($check['required']);
            self::assertStringContainsString($expected, $check['detail']);
            self::assertStringNotContainsString(':p@', $check['detail']);
        }
    }

    public function testTheContainerMailerIsTheRecordingOneInTests(): void
    {
        self::assertInstanceOf(RecordingMailer::class, $this->container->get(Mailer::class));
    }
}
