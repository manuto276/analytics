<?php

declare(strict_types=1);

namespace Analytics\Retention\Console;

use Analytics\Retention\Application\PartitionManager;
use Analytics\Shared\Jobs\JobRunner;
use Analytics\Shared\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'partitions:maintain', description: 'Creates the monthly partitions of the coming months')]
final class PartitionsMaintainCommand extends Command
{
    public function __construct(private readonly PartitionManager $partitions, private readonly JobRunner $jobs, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('ahead', null, InputOption::VALUE_REQUIRED, 'Months to prepare', '3')
            ->addOption('past-from', null, InputOption::VALUE_REQUIRED, 'Also split the catch-all partition into months from this day (for imported history)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->partitions->enabled()) {
            $output->writeln('Partitioning is disabled (DB_PARTITIONING=false); nothing to do.');

            return self::SUCCESS;
        }
        $ahead = max(1, min(12, Types::int($input->getOption('ahead'), 3)));
        $result = $this->jobs->run('partitions:maintain', fn(): array => $this->partitions->maintain($this->clock->now(), $ahead), 600);
        $output->writeln('partitions:maintain ' . $result['status'] . ' ' . json_encode($result['stats'], \JSON_THROW_ON_ERROR));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
