<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Identity\Application\UserService;
use Analytics\Identity\Domain\GlobalRole;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:create-admin', description: 'Creates a global admin (first admin or recovery)')]
final class UserCreateAdminCommand extends Command
{
    use ReadsPassword;

    public function __construct(private readonly UserService $users, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name', 'Administrator')
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from standard input')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'en or it', 'en');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = Types::string($input->getOption('email'));
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>--email must be a valid email address.</error>');

            return self::INVALID;
        }
        $password = $this->readPassword($input, $output);
        if ($password === null) {
            return self::INVALID;
        }
        try {
            $user = $this->users->create($email, $password, Types::string($input->getOption('name')), GlobalRole::Admin, Types::string($input->getOption('locale')));
        } catch (ApiProblem $e) {
            $output->writeln('<error>' . ($e->errors === [] ? (string) $e->detail : json_encode($e->errors, \JSON_THROW_ON_ERROR)) . '</error>');

            return self::FAILURE;
        }
        $this->audit->log('user.created', Actor::console(), null, 'user', $user->id(), ['global_role' => 'admin']);
        $output->writeln(\sprintf('Admin %s created (id %d).', $user->email, $user->id()));

        return self::SUCCESS;
    }
}
