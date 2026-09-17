<?php

declare(strict_types=1);

namespace Analytics\Tracking\Console;

use Analytics\Shared\Jobs\JobRunner;
use Analytics\Tracking\Application\DailySaltProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'salt:rotate', description: 'Ensures today\'s visitor-hash salt exists and destroys older salts')]
final class SaltRotateCommand extends Command
{
    public function __construct(private readonly DailySaltProvider $salts, private readonly JobRunner $jobs, private readonly ClockInterface $clock)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->jobs->run('salt:rotate', fn(): array => ['deleted' => $this->salts->rotate($this->clock->now())], 300);
        $output->writeln('salt:rotate ' . $result['status'] . ($result['message'] !== null ? ': ' . $result['message'] : ''));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
