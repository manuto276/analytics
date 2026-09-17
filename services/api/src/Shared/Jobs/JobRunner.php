<?php

declare(strict_types=1);

namespace Analytics\Shared\Jobs;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Runs scheduled jobs under a lock and records them in job_runs.
 */
final readonly class JobRunner
{
    public function __construct(
        private Connection $connection,
        private LockFactory $locks,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * @template T of array<string, mixed>
     *
     * @param callable(): T $job returns stats
     *
     * @return array{status: string, stats: array<string, mixed>, message: ?string}
     */
    public function run(string $name, callable $job, int $lockTtl = 3600): array
    {
        $lock = $this->locks->createLock('job:' . $name, $lockTtl);
        if (!$lock->acquire(false)) {
            return ['status' => 'skipped', 'stats' => [], 'message' => 'Another run holds the lock.'];
        }
        $started = $this->clock->now();
        $this->connection->insert('job_runs', ['job' => $name, 'started_at' => self::ts($started), 'status' => 'running']);
        $id = (int) $this->connection->lastInsertId();
        try {
            $stats = $job();
            $this->finish($id, 'succeeded', null, $stats);

            return ['status' => 'succeeded', 'stats' => $stats, 'message' => null];
        } catch (\Throwable $e) {
            $message = mb_substr($e::class . ': ' . $e->getMessage(), 0, 1000);
            // A job that gave up part of the way through keeps the counts it did produce, so that
            // the failed run still tells the operator how much of it went through.
            $stats = $e instanceof JobStats ? $e->jobStats() : [];
            $this->logger->error('Job failed', ['job' => $name, 'error' => $message, 'stats' => $stats]);
            try {
                $this->finish($id, 'failed', $message, $stats);
            } catch (\Throwable) {
                // The database may be the reason of the failure.
            }

            return ['status' => 'failed', 'stats' => $stats, 'message' => $message];
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $stats */
    private function finish(int $id, string $status, ?string $message, array $stats): void
    {
        $this->connection->update('job_runs', [
            'finished_at' => self::ts($this->clock->now()),
            'status' => $status,
            'message' => $message,
            'stats' => json_encode($stats, \JSON_THROW_ON_ERROR),
        ], ['id' => $id]);
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
