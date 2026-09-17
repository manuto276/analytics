<?php

declare(strict_types=1);

namespace Analytics\Reporting\Console;

use Analytics\Reporting\Application\JobsStatus;
use Analytics\Shared\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'jobs:status', description: 'Shows the last run of each scheduled job and the rollup lag')]
final class JobsStatusCommand extends Command
{
    public function __construct(private readonly JobsStatus $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->status->snapshot();
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($status, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $rows = [];
        foreach (\is_array($status['jobs']) ? $status['jobs'] : [] as $job) {
            $job = \is_array($job) ? $job : [];
            $rows[] = [
                Types::string($job['job'] ?? ''),
                Types::string($job['last_status'] ?? '-', '-'),
                Types::string($job['last_started_at'] ?? '-', '-'),
                Types::string($job['last_duration_ms'] ?? '-', '-'),
                \is_array($job['last_stats'] ?? null) ? json_encode($job['last_stats'], \JSON_THROW_ON_ERROR) : '-',
                mb_substr(Types::string($job['last_message'] ?? ''), 0, 60),
            ];
        }
        new Table($output)->setHeaders(['job', 'status', 'started', 'ms', 'stats', 'message'])->setRows($rows)->render();
        $geoAge = Types::nullableInt($status['geo_db_age_days'] ?? null);
        $output->writeln(\sprintf(
            'rollup lag: %d min over %d dirty day(s); geo database: %s',
            Types::int($status['rollup_lag_minutes'] ?? 0),
            Types::int($status['dirty_days'] ?? 0),
            $geoAge === null ? 'missing' : $geoAge . ' days old',
        ));

        return self::SUCCESS;
    }
}
