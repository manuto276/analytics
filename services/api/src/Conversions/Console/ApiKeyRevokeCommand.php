<?php

declare(strict_types=1);

namespace Analytics\Conversions\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api-key:revoke', description: 'Revokes a server API key by its prefix')]
final class ApiKeyRevokeCommand extends Command
{
    public function __construct(private readonly ApiKeyService $keys, private readonly SiteRepository $sites, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('prefix', InputArgument::REQUIRED, 'The 8-character key prefix')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site id or public key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getOption('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        if ($site === null) {
            $output->writeln('<error>Site not found (use --site).</error>');

            return self::INVALID;
        }
        $prefix = Types::string($input->getArgument('prefix'));
        foreach ($this->keys->forSite($site->id()) as $key) {
            if ($key->prefix === $prefix) {
                $this->keys->revoke($site->id(), $key->id());
                $this->audit->log('api_key.revoked', Actor::console(), $site->id(), 'api_key', $key->id());
                $output->writeln('Key revoked.');

                return self::SUCCESS;
            }
        }
        $output->writeln('<error>No key with this prefix for the site.</error>');

        return self::FAILURE;
    }
}
