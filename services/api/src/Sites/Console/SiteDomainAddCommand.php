<?php

declare(strict_types=1);

namespace Analytics\Sites\Console;

use Analytics\Shared\Types;
use Analytics\Sites\Application\DomainMatcher;
use Analytics\Sites\Application\SiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'site:domain:add', description: 'Adds a domain to a site')]
final class SiteDomainAddCommand extends Command
{
    public function __construct(private readonly SiteRepository $sites, private readonly EntityManagerInterface $em, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site id or public key')
            ->addArgument('host', InputArgument::REQUIRED)
            ->addOption('include-subdomains', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getArgument('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        $host = DomainMatcher::normalizeHost(Types::string($input->getArgument('host')));
        if ($site === null || $host === null) {
            $output->writeln('<error>' . ($site === null ? 'Site not found.' : 'Invalid host.') . '</error>');

            return self::FAILURE;
        }
        $site->addDomain($host, $input->getOption('include-subdomains') === true, $this->clock->now());
        $this->em->flush();
        $this->sites->forgetSnapshot($site);
        $output->writeln(\sprintf('Domain %s added to %s.', $host, $site->name));

        return self::SUCCESS;
    }
}
