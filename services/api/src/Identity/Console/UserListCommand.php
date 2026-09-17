<?php

declare(strict_types=1);

namespace Analytics\Identity\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'user:list', description: 'Lists users')]
final class UserListCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id, u.email, u.display_name, u.global_role, u.status, IF(t.confirmed_at IS NULL, \'no\', \'yes\') AS mfa, u.last_login_at
               FROM users u LEFT JOIN totp_credentials t ON t.user_id = u.id ORDER BY u.id',
        );
        new Table($output)->setHeaders(['id', 'email', 'name', 'role', 'status', 'mfa', 'last login'])->setRows(array_map('array_values', $rows))->render();

        return self::SUCCESS;
    }
}
