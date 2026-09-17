<?php

declare(strict_types=1);

namespace Analytics\Health;

use Analytics\Kernel\BuildInfo;
use Analytics\Kernel\Settings;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Psr\Clock\ClockInterface;

/**
 * Health checks: database, pending migrations, rollup lag, daily salt, geo database age, disk space, last job runs.
 */
final readonly class HealthChecker
{
    public const int ROLLUP_LAG_WARN_MINUTES = 30;
    public const int GEO_MAX_AGE_DAYS = 45;

    public function __construct(
        private Connection $connection,
        private DependencyFactory $migrations,
        private Settings $settings,
        private BuildInfo $build,
        private ClockInterface $clock,
    ) {}

    /** @return array{status: string, checks: array<string, array{status: string, detail: string}>} */
    public function run(): array
    {
        $checks = [];
        $now = $this->clock->now();

        try {
            $this->connection->fetchOne('SELECT 1');
            $checks['database'] = ['status' => 'ok', 'detail' => 'connected'];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'fail', 'detail' => 'unreachable'];

            return ['status' => 'fail', 'checks' => $checks];
        }

        try {
            $pending = \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations());
            $checks['migrations'] = ['status' => $pending === 0 ? 'ok' : 'fail', 'detail' => $pending . ' pending'];
        } catch (\Throwable) {
            $checks['migrations'] = ['status' => 'fail', 'detail' => 'status unavailable'];
        }

        if ($checks['migrations']['status'] === 'ok') {
            $oldestDirty = $this->connection->fetchOne('SELECT MIN(marked_at) FROM rollup_dirty');
            if (\is_string($oldestDirty)) {
                $lag = (int) floor(($now->getTimestamp() - new \DateTimeImmutable($oldestDirty, new \DateTimeZone('UTC'))->getTimestamp()) / 60);
                $checks['rollup_lag'] = ['status' => $lag > self::ROLLUP_LAG_WARN_MINUTES ? 'warn' : 'ok', 'detail' => $lag . ' min'];
            } else {
                $checks['rollup_lag'] = ['status' => 'ok', 'detail' => '0 min'];
            }

            $salt = Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM daily_salts WHERE day < ?', [$now->format('Y-m-d')]));
            $checks['salt'] = ['status' => $salt === 0 ? 'ok' : 'warn', 'detail' => $salt === 0 ? 'no stale salts' : $salt . ' stale salt(s); run salt:rotate'];

            $lastJob = $this->connection->fetchAssociative("SELECT job, status, started_at FROM job_runs WHERE status = 'failed' AND started_at > ? ORDER BY started_at DESC LIMIT 1", [$now->modify('-1 day')->format('Y-m-d H:i:s')]);
            $checks['jobs'] = \is_array($lastJob)
                ? ['status' => 'warn', 'detail' => \sprintf('%s failed at %s', Types::string($lastJob['job']), Types::string($lastJob['started_at']))]
                : ['status' => 'ok', 'detail' => 'no failures in 24h'];
        }

        $geo = $this->settings->geoDbPath;
        if (is_file($geo)) {
            $age = (int) floor(($now->getTimestamp() - (int) filemtime($geo)) / 86400);
            $checks['geo_db'] = ['status' => $age > self::GEO_MAX_AGE_DAYS ? 'warn' : 'ok', 'detail' => $age . ' days old'];
        } else {
            $checks['geo_db'] = ['status' => 'warn', 'detail' => 'missing (countries unavailable); run geo:update'];
        }

        $dir = is_dir($this->settings->storageDir) ? $this->settings->storageDir : $this->settings->projectDir;
        $free = @disk_free_space($dir);
        if ($free !== false) {
            $checks['disk'] = ['status' => $free < 1024 ** 3 ? 'warn' : 'ok', 'detail' => round($free / 1024 ** 3, 1) . ' GB free'];
        }

        $statuses = array_column($checks, 'status');
        $status = \in_array('fail', $statuses, true) ? 'fail' : (\in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return array<string, string> */
    public function buildInfo(): array
    {
        return ['version' => $this->build->version, 'commit' => $this->build->commit];
    }
}
