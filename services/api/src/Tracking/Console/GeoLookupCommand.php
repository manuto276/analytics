<?php

declare(strict_types=1);

namespace Analytics\Tracking\Console;

use Analytics\Shared\Net\IpTruncator;
use Analytics\Shared\Types;
use Analytics\Tracking\Application\GeoLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'geo:lookup', description: 'Looks up the country of an address (after shortening it, like ingestion does)')]
final class GeoLookupCommand extends Command
{
    public function __construct(private readonly GeoLocator $geo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('ip', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prefix = IpTruncator::truncate(Types::string($input->getArgument('ip')));
        if ($prefix === null) {
            $output->writeln('<error>Invalid IP address.</error>');

            return self::INVALID;
        }
        $output->writeln(\sprintf('%s → %s', (string) $prefix, $this->geo->country($prefix) ?? 'unknown'));

        return self::SUCCESS;
    }
}
