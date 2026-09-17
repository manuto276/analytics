<?php

declare(strict_types=1);

namespace Analytics\Retention\Console;

use Analytics\Retention\Application\RetentionPurger;
use Analytics\Shared\Jobs\JobRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'retention:purge', description: 'Removes raw data past the retention window (rollups are kept)')]
final class RetentionPurgeCommand extends Command
{
    public function __construct(private readonly RetentionPurger $purger, private readonly JobRunner $jobs)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be removed')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Purge even when days are still waiting for a rollup');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run') === true;
        $dirty = $this->purger->dirtyDaysBeforeCutoff();
        if ($dirty > 0 && !$dryRun && $input->getOption('force') !== true) {
            $output->writeln(\sprintf('<error>%d day(s) before the cutoff still need rollup:run; refusing to purge (use --force to override).</error>', $dirty));

            return self::FAILURE;
        }
        if ($dryRun) {
            $output->writeln(json_encode($this->purger->purge(true), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $result = $this->jobs->run('retention:purge', fn(): array => $this->purger->purge(false), 3600);
        $output->writeln('retention:purge ' . $result['status'] . ' ' . json_encode($result['stats'], \JSON_THROW_ON_ERROR));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
