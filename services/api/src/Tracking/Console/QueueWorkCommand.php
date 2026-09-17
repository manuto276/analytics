<?php

declare(strict_types=1);

namespace Analytics\Tracking\Console;

use Analytics\Kernel\Settings;
use Analytics\Shared\Types;
use Analytics\Tracking\Application\IngestBatchHandler;
use Analytics\Tracking\Infrastructure\RedisQueueEventSink;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'queue:work', description: 'Writes queued events (INGEST_MODE=queue)')]
final class QueueWorkCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ContainerInterface $container,
        private readonly IngestBatchHandler $handler,
        private readonly LockFactory $locks,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-time', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds', '55')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Events per transaction', '500')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process what is queued and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->settings->redisDsn === null) {
            $output->writeln('<error>queue:work needs REDIS_DSN.</error>');

            return self::FAILURE;
        }
        $queue = $this->container->get(RedisQueueEventSink::class);
        \assert($queue instanceof RedisQueueEventSink);
        $lock = $this->locks->createLock('job:queue:work', 120);
        if (!$lock->acquire(false)) {
            $output->writeln('queue:work already running.', OutputInterface::VERBOSITY_VERBOSE);

            return self::SUCCESS;
        }
        $deadline = microtime(true) + max(1, Types::int($input->getOption('max-time'), 55));
        $batch = max(1, min(5000, Types::int($input->getOption('batch'), 500)));
        $stored = 0;
        try {
            while (microtime(true) < $deadline) {
                $drafts = $queue->pop($batch);
                if ($drafts === []) {
                    if ($input->getOption('once') === true) {
                        break;
                    }
                    usleep(500_000);
                    $lock->refresh();
                    continue;
                }
                try {
                    $stored += $this->handler->handle($drafts);
                } catch (\Throwable $e) {
                    $this->logger->error('queue:work batch failed; re-queueing', ['error' => $e->getMessage()]);
                    $queue->accept($drafts);

                    return self::FAILURE;
                }
                $lock->refresh();
            }
        } finally {
            $lock->release();
        }
        $output->writeln(\sprintf('queue:work stored %d events.', $stored), OutputInterface::VERBOSITY_VERBOSE);

        return self::SUCCESS;
    }
}
