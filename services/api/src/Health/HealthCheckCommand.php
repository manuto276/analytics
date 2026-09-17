<?php

declare(strict_types=1);

namespace Analytics\Health;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'health:check', description: 'Runs health checks; exits non-zero on failure')]
final class HealthCheckCommand extends Command
{
    public function __construct(private readonly HealthChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'JSON output')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as failures');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->checker->run() + $this->checker->buildInfo();
        if ($input->getOption('json') === true) {
            $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } else {
            foreach ($result['checks'] as $name => $check) {
                $output->writeln(\sprintf('%-6s %-12s %s', $check['status'], $name, $check['detail']));
            }
            $output->writeln('overall: ' . $result['status']);
        }
        $failed = $result['status'] === 'fail' || ($input->getOption('strict') === true && $result['status'] === 'warn');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
