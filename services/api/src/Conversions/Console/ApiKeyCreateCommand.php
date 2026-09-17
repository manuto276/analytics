<?php

declare(strict_types=1);

namespace Analytics\Conversions\Console;

use Analytics\Audit\Application\Actor;
use Analytics\Audit\Application\AuditLogger;
use Analytics\Conversions\Application\ApiKeyService;
use Analytics\Shared\Http\ApiProblem;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api-key:create', description: 'Creates a server API key (the secret is shown once)')]
final class ApiKeyCreateCommand extends Command
{
    public function __construct(private readonly ApiKeyService $keys, private readonly SiteRepository $sites, private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site id or public key')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Label', 'server')
            ->addOption('scopes', null, InputOption::VALUE_REQUIRED, 'Comma-separated scopes', 'conversions:write');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getOption('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        if ($site === null) {
            $output->writeln('<error>Site not found (use --site).</error>');

            return self::INVALID;
        }
        $scopes = array_values(array_filter(array_map('trim', explode(',', Types::string($input->getOption('scopes')))), static fn(string $scope): bool => $scope !== ''));
        try {
            [$key, $secret] = $this->keys->create($site->id(), Types::string($input->getOption('name'), 'server'), $scopes, null);
        } catch (ApiProblem $e) {
            $output->writeln('<error>' . json_encode($e->errors, \JSON_THROW_ON_ERROR) . '</error>');

            return self::FAILURE;
        }
        $this->audit->log('api_key.created', Actor::console(), $site->id(), 'api_key', $key->id(), ['key_prefix' => $key->prefix]);
        $output->writeln('API key (store it now, it is not shown again):');
        $output->writeln($secret, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
