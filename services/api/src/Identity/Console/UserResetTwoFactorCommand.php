<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Application\UserService;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:reset-2fa', description: 'Removes TOTP and recovery codes of a user')]
final class UserResetTwoFactorCommand extends Command
{
    public function __construct(private readonly UserService $users, private readonly TotpService $totp, private readonly SessionManager $sessions, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findByEmail(Types::string($input->getArgument('email')));
        if ($user === null) {
            $output->writeln('<error>User not found.</error>');

            return self::FAILURE;
        }
        $this->totp->disable($user->id());
        $this->sessions->revokeAll($user->id());
        $this->audit->log('user.totp_reset', Actor::console(), null, 'user', $user->id());
        $output->writeln('Two-factor authentication removed; sessions revoked.');

        return self::SUCCESS;
    }
}
