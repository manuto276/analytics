<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Consent\Domain\ConsentConfig;
use Analytics\Kernel\Settings;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Operational status for `jobs:status` and the dashboard jobs slideover.
 */
final readonly class JobsStatus
{
    public const array JOBS = ['rollup:run', 'rollup:rebuild', 'salt:rotate', 'retention:purge', 'partitions:maintain', 'geo:update', 'conversions:reattribute'];

    public function __construct(private Connection $connection, private Settings $settings, private ClockInterface $clock) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = $this->clock->now();
        $jobs = [];
        foreach (self::JOBS as $job) {
            $row = $this->connection->fetchAssociative(
                'SELECT started_at, finished_at, status, message, stats FROM job_runs WHERE job = ? ORDER BY started_at DESC LIMIT 1',
                [$job],
            );
            $duration = null;
            if (\is_array($row) && \is_string($row['finished_at'] ?? null)) {
                $started = new \DateTimeImmutable(Types::string($row['started_at']), new \DateTimeZone('UTC'));
                $finished = new \DateTimeImmutable(Types::string($row['finished_at']), new \DateTimeZone('UTC'));
                $duration = (int) round(((float) $finished->format('U.u') - (float) $started->format('U.u')) * 1000);
            }
            $jobs[] = [
                'job' => $job,
                'last_started_at' => \is_array($row) ? self::iso(Types::nullableString($row['started_at'])) : null,
                'last_status' => \is_array($row) ? Types::nullableString($row['status']) : null,
                'last_message' => \is_array($row) ? Types::nullableString($row['message']) : null,
                'last_duration_ms' => $duration,
                // Kept even on a failed run: a job that gave up part of the way through still says
                // how much it managed to do.
                'last_stats' => \is_array($row) ? self::stats(Types::nullableString($row['stats'])) : null,
            ];
        }

        $oldestDirty = $this->connection->fetchOne('SELECT MIN(first_marked_at) FROM rollup_dirty');
        $lag = \is_string($oldestDirty)
            ? max(0, (int) floor(($now->getTimestamp() - new \DateTimeImmutable($oldestDirty, new \DateTimeZone('UTC'))->getTimestamp()) / 60))
            : 0;

        $geoAge = is_file($this->settings->geoDbPath) ? (int) floor(($now->getTimestamp() - (int) filemtime($this->settings->geoDbPath)) / 86400) : null;

        $drafts = $this->connection->fetchAllAssociative(
            'SELECT c.site_id, s.name FROM consent_configs c JOIN sites s ON s.id = c.site_id WHERE c.status = ? ORDER BY s.name',
            [ConsentConfig::DRAFT],
        );

        return [
            'jobs' => $jobs,
            'rollup_lag_minutes' => $lag,
            'dirty_days' => Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM rollup_dirty')),
            'geo_db_age_days' => $geoAge,
            'pending_consent_drafts' => array_map(static fn(array $row): array => ['site_id' => Types::int($row['site_id']), 'site_name' => Types::string($row['name'])], $drafts),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function stats(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }
        $stats = [];
        foreach ($decoded as $key => $value) {
            $stats[(string) $key] = $value;
        }

        return $stats;
    }

    private static function iso(?string $value): ?string
    {
        return $value === null ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
