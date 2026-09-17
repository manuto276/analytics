<?php

declare(strict_types=1);

namespace Analytics\Sites\Console;

use Analytics\Sites\Application\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'site:list', description: 'Lists sites')]
final class SiteListCommand extends Command
{
    public function __construct(private readonly SiteRepository $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('archived', null, InputOption::VALUE_NONE, 'Include archived sites');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        foreach ($this->sites->all($input->getOption('archived') === true) as $site) {
            $domains = [];
            foreach ($site->domains as $d) {
                $domains[] = ($d->includeSubdomains ? '*.' : '') . $d->host;
            }
            $rows[] = [$site->id(), $site->name, $site->publicKey, implode(', ', $domains), $site->timezone, $site->visitorHashMode->value, $site->cookieLevelEnabled ? 'on' : 'off', $site->isArchived() ? 'archived' : 'active'];
        }
        new Table($output)->setHeaders(['id', 'name', 'public key', 'domains', 'timezone', 'hash mode', 'cookie level', 'status'])->setRows($rows)->render();

        return self::SUCCESS;
    }
}
