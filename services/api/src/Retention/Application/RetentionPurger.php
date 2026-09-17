<?php

declare(strict_types=1);

namespace Analytics\Retention\Application;

use Analytics\Kernel\Settings;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Enforces retention: raw events, visits, visitors, touches, conversions and receipts are removed
 * after RETENTION_MONTHS; rollups are kept. Operational tables have their own shorter windows.
 */
final readonly class RetentionPurger
{
    public const int AUDIT_MONTHS = 24;
    public const int JOB_RUNS_DAYS = 90;
    public const int ACCEPTED_INVITATION_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private PartitionManager $partitions,
        private Settings $settings,
        private ClockInterface $clock,
    ) {}

    public function cutoff(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('-' . $this->settings->retentionMonths . ' months')->setTime(0, 0);
    }

    /** Days still waiting for a rollup inside the range that would be purged. */
    public function dirtyDaysBeforeCutoff(): int
    {
        return Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE day < ?', [$this->cutoff()->format('Y-m-d')]));
    }

    /**
     * @return array<string, mixed>
     */
    public function purge(bool $dryRun = false): array
    {
        $cutoff = $this->cutoff();
        $now = $this->clock->now();
        $stats = [
            'cutoff' => $cutoff->format('Y-m-d'),
            'dropped_partitions' => [],
            'events_raw' => 0,
            'visits' => 0,
            'visit_lookup' => 0,
            'consent_stat_uids' => 0,
            'visitors' => 0,
            'attribution_touches' => 0,
            'conversions' => 0,
            'consent_receipts' => 0,
            'consent_stats_daily' => 0,
            'auth_sessions' => 0,
            'invitations' => 0,
            'password_resets' => 0,
            'audit_log' => 0,
            'job_runs' => 0,
        ];
        if ($dryRun) {
            $stats['events_raw'] = Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM events_raw WHERE local_day < ?', [$cutoff->format('Y-m-d')]));
            $stats['visits'] = Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM visits WHERE local_day < ?', [$cutoff->format('Y-m-d')]));
            $stats['conversions'] = Types::int($this->connection->fetchOne('SELECT COUNT(*) FROM conversions WHERE local_day < ?', [$cutoff->format('Y-m-d')]));

            return $stats;
        }

        foreach (['events_raw', 'visits', 'consent_stat_uids'] as $table) {
            if ($this->partitions->enabled() && $this->partitions->isPartitioned($table)) {
                $dropped = $this->partitions->dropBefore($table, $cutoff);
                $stats['dropped_partitions'] = array_merge($stats['dropped_partitions'], $dropped);
            }
            // Rows that survive in a partition that still covers newer days (or when partitioning is off).
            $stats[$table] = $this->partitions->deleteBefore($table, 'local_day', $cutoff);
        }

        $stats['visit_lookup'] = $this->partitions->deleteBefore('visit_lookup', 'visit_day', $cutoff);
        $stats['attribution_touches'] = $this->partitions->deleteBefore('attribution_touches', 'touched_at', $cutoff);
        $stats['conversions'] = $this->partitions->deleteBefore('conversions', 'local_day', $cutoff);
        $stats['consent_receipts'] = $this->partitions->deleteBefore('consent_receipts', 'decided_at', $cutoff);
        $stats['visitors'] = $this->partitions->deleteBefore('visitors', 'last_seen_at', $cutoff);
        $stats['consent_stats_daily'] = $this->partitions->deleteBefore('consent_stats_daily', 'day', $cutoff);

        $stats['auth_sessions'] = Types::int($this->connection->executeStatement(
            'DELETE FROM auth_sessions WHERE absolute_expires_at < ? OR idle_expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?)',
            array_fill(0, 3, $now->format('Y-m-d H:i:s')),
        ));
        $stats['invitations'] = Types::int($this->connection->executeStatement(
            'DELETE FROM invitations WHERE (accepted_at IS NOT NULL AND accepted_at < ?) OR (accepted_at IS NULL AND expires_at < ?)',
            [$now->modify('-' . self::ACCEPTED_INVITATION_DAYS . ' days')->format('Y-m-d H:i:s'), $now->modify('-' . self::ACCEPTED_INVITATION_DAYS . ' days')->format('Y-m-d H:i:s')],
        ));
        $stats['password_resets'] = Types::int($this->connection->executeStatement('DELETE FROM password_resets WHERE expires_at < ?', [$now->format('Y-m-d H:i:s')]));
        if ($stats['events_raw'] > 0 || $stats['visits'] > 0 || $stats['conversions'] > 0 || $stats['dropped_partitions'] !== []) {
            // Reports cached before the purge would still show the removed data.
            $this->connection->executeStatement('UPDATE sites SET rollup_version = rollup_version + 1');
        }
        $stats['audit_log'] = $this->partitions->deleteBefore('audit_log', 'occurred_at', $now->modify('-' . self::AUDIT_MONTHS . ' months'));
        $stats['job_runs'] = $this->partitions->deleteBefore('job_runs', 'started_at', $now->modify('-' . self::JOB_RUNS_DAYS . ' days'));

        return $stats;
    }
}
