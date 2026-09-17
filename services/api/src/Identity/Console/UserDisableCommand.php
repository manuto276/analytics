<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\UserStatus;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:disable', description: 'Disables (or re-enables with --enable) a user and revokes their sessions')]
final class UserDisableCommand extends Command
{
    public function __construct(private readonly UserService $users, private readonly SessionManager $sessions, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addOption('enable', null, InputOption::VALUE_NONE, 'Re-enable instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findByEmail(Types::string($input->getArgument('email')));
        if ($user === null) {
            $output->writeln('<error>User not found.</error>');

            return self::FAILURE;
        }
        $enable = $input->getOption('enable') === true;
        try {
            $this->users->setStatus($user, $enable ? UserStatus::Active : UserStatus::Disabled);
        } catch (ApiProblem $e) {
            $output->writeln('<error>' . $e->detail . '</error>');

            return self::FAILURE;
        }
        if (!$enable) {
            $this->sessions->revokeAll($user->id());
        }
        $this->audit->log($enable ? 'user.enabled' : 'user.disabled', Actor::console(), null, 'user', $user->id());
        $output->writeln(\sprintf('User %s %s.', $user->email, $enable ? 'enabled' : 'disabled'));

        return self::SUCCESS;
    }
}
