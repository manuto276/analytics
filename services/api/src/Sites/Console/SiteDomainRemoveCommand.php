<?php

declare(strict_types=1);

namespace Analytics\Sites\Console;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Domain\DomainMatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'site:domain:remove', description: 'Removes a domain from a site')]
final class SiteDomainRemoveCommand extends Command
{
    public function __construct(private readonly SiteRepository $sites, private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Site id or public key')->addArgument('host', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ref = Types::string($input->getArgument('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        $host = DomainMatcher::normalizeHost(Types::string($input->getArgument('host')));
        if ($site === null || $host === null || !$site->removeDomain($host)) {
            $output->writeln('<error>Site or domain not found.</error>');

            return self::FAILURE;
        }
        $this->em->flush();
        $this->sites->forgetSnapshot($site);
        $output->writeln(\sprintf('Domain %s removed from %s.', $host, $site->name));

        return self::SUCCESS;
    }
}
