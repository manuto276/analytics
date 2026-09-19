<?php

declare(strict_types=1);

namespace Analytics\Kernel\Console;

use Analytics\Kernel\Settings;
use Analytics\Shared\Mail\Mailer;
use Analytics\Shared\Mail\MailLayout;
use Analytics\Shared\Mail\SymfonyMailer;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mail:test', description: 'Sends a test message through MAILER_DSN and prints the transport error if it fails')]
final class MailTestCommand extends Command
{
    public function __construct(private readonly Mailer $mailer, private readonly Settings $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = trim(Types::string($input->getOption('to') ?? ''));
        if ($to === '' || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>--to must be an email address.</error>');

            return self::INVALID;
        }
        $dsn = $this->settings->mailerDsn;
        if (!$this->mailer->isEnabled() || $dsn === null) {
            $output->writeln('<error>No mailer configured: set MAILER_DSN (see docs/operations/mail.md).</error>');

            return self::FAILURE;
        }
        $problem = SymfonyMailer::dsnProblem($dsn);
        if ($problem !== null) {
            $output->writeln('<error>MAILER_DSN cannot be used: ' . $problem . '</error>');

            return self::FAILURE;
        }

        $via = SymfonyMailer::describeDsn($dsn);
        $from = \sprintf('%s <%s>', $this->settings->mailFromName, $this->settings->mailFrom);
        $output->writeln(\sprintf('Sending a test message to %s via %s from %s ...', $to, $via, $from));
        $host = $this->settings->appHost();
        try {
            $this->mailer->send(MailLayout::render(
                to: $to,
                subject: 'Test message from ' . $host,
                locale: 'en',
                heading: 'Email delivery works',
                paragraphs: [
                    'This is a test message sent with `bin/analytics mail:test` by the analytics service at ' . $host . '.',
                    'Password reset and email change messages will be sent through the same account (' . $via . ') with the sender ' . $from . '.',
                ],
                action: null,
                actionFallback: '',
                after: ['If it landed in spam, check the SPF, DKIM and DMARC records of the sender domain (docs/operations/mail.md).'],
                footer: 'Sent by the analytics service at ' . $host . '. This message contains no tracking.',
            ));
        } catch (\Throwable $e) {
            $output->writeln('<error>Sending failed: ' . $e->getMessage() . '</error>');
            $output->writeln('Check the host, port, scheme (smtp:// for STARTTLS on 587, smtps:// for 465), the credentials (URL-encoded) and that MAIL_FROM is an address the account may send as.');

            return self::FAILURE;
        }
        $output->writeln('<info>Sent.</info> The transport accepted the message; check the inbox (and the spam folder) of ' . $to . '.');

        return self::SUCCESS;
    }
}
