<?php

declare(strict_types=1);

namespace Analytics\Reporting\Console;

use Analytics\Kernel\Settings;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tracking\Application\Seeder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:seed', description: 'Fills a site with deterministic demo data (never in production)')]
final class DevSeedCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SiteRepository $sites,
        private readonly Seeder $seeder,
        private readonly RollupRunner $rollups,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site id or public key')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days of history', '60')
            ->addOption('visits', null, InputOption::VALUE_REQUIRED, 'Visits per day', '40')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed', '20260917')
            ->addOption('no-rollup', null, InputOption::VALUE_NONE, 'Skip building rollups afterwards');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->settings->isProd()) {
            $output->writeln('<error>dev:seed refuses to run with APP_ENV=prod.</error>');

            return self::FAILURE;
        }
        $ref = Types::string($input->getOption('site'));
        $site = ctype_digit($ref) ? $this->sites->find((int) $ref) : $this->sites->findByPublicKey($ref);
        if ($site === null) {
            $output->writeln('<error>Site not found (use --site).</error>');

            return self::INVALID;
        }
        $snapshot = SiteSnapshot::fromSite($site);
        $days = max(1, Types::int($input->getOption('days'), 60));
        $visits = max(1, Types::int($input->getOption('visits'), 40));
        $today = $this->clock->now()->setTimezone($snapshot->timezone())->setTime(0, 0);

        $stats = $this->seeder->seed($snapshot, $today, $days, $visits, Types::int($input->getOption('seed'), 20260917));
        $output->writeln(\sprintf('Seeded %d visits, %d events and %d conversions over %d days.', $stats['visits'], $stats['events'], $stats['conversions'], $days));

        if ($input->getOption('no-rollup') !== true) {
            do {
                $result = $this->rollups->runDirty($site->id());
            } while ($result['days'] > 0);
            $output->writeln('Rollups built.');
        }

        return self::SUCCESS;
    }
}
