<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\UserService;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:set-password', description: 'Sets a user password, unlocks the account and revokes sessions')]
final class UserSetPasswordCommand extends Command
{
    use ReadsPassword;

    public function __construct(private readonly UserService $users, private readonly SessionManager $sessions, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from standard input');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->users->findByEmail(Types::string($input->getArgument('email')));
        if ($user === null) {
            $output->writeln('<error>User not found.</error>');

            return self::FAILURE;
        }
        $password = $this->readPassword($input, $output);
        if ($password === null) {
            return self::INVALID;
        }
        try {
            $this->users->setPassword($user, $password);
        } catch (ApiProblem $e) {
            $output->writeln('<error>' . json_encode($e->errors, \JSON_THROW_ON_ERROR) . '</error>');

            return self::FAILURE;
        }
        $this->sessions->revokeAll($user->id());
        $this->audit->log('user.password_set', Actor::console(), null, 'user', $user->id());
        $output->writeln('Password updated; sessions revoked.');

        return self::SUCCESS;
    }
}
