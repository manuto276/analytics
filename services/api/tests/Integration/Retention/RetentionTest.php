<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Retention;

use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Retention\Application\PartitionManager;
use Analytics\Retention\Application\RetentionPurger;
use Analytics\Shared\Doctrine\Partitioning;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tracking\Application\Seeder;

/**
 * Retention removes raw data after 13 months (dropping whole partitions where possible) and
 * keeps the rollups, so old reports stay available.
 */
final class RetentionTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    public function testPurgeRemovesOldRawDataButKeepsRollups(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true], ['www.example.com']);
        $snapshot = SiteSnapshot::fromSite($site);
        $seeder = $this->service(Seeder::class);
        $partitions = $this->service(PartitionManager::class);

        // Two days of data now, and two days fourteen months ago.
        $seeder->seed($snapshot, $this->clock->now(), 2, 3, 1);
        $old = $this->clock->now()->modify('-14 months');
        $partitions->ensurePastMonths($old->modify('-1 month'));
        $this->clock->modify($old->format('Y-m-d H:i:s'));
        $seeder->seed($snapshot, $old, 2, 3, 2);
        $this->clock->modify('2026-09-17 10:00:00');

        $runner = $this->service(RollupRunner::class);
        do {
            $result = $runner->runDirty($site->id());
        } while ($result['days'] > 0);

        $oldDay = $old->format('Y-m-d');
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE local_day = ?', [$oldDay]));
        $rollupBefore = (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_overview_daily WHERE day = ?', [$oldDay]);
        self::assertGreaterThan(0, $rollupBefore);

        $purger = $this->service(RetentionPurger::class);
        self::assertSame(0, $purger->dirtyDaysBeforeCutoff());
        $dryRun = $purger->purge(true);
        self::assertGreaterThan(0, $dryRun['events_raw']);
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE local_day = ?', [$oldDay]), 'a dry run deletes nothing');

        $stats = $purger->purge();

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE local_day = ?', [$oldDay]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visits WHERE local_day = ?', [$oldDay]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM attribution_touches WHERE touched_at < ?', [$purger->cutoff()->format('Y-m-d')]));
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE local_day >= ?', [$this->clock->now()->modify('-2 days')->format('Y-m-d')]), 'recent data stays');
        self::assertSame($rollupBefore, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_overview_daily WHERE day = ?', [$oldDay]), 'rollups are kept');
        self::assertNotSame([], $stats['dropped_partitions'], 'whole months are dropped, not deleted row by row');

        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            self::assertArrayNotHasKey(Partitioning::partitionName($old), $partitions->partitions($table));
        }
    }

    public function testPurgeRefusesWhenDaysStillNeedARollup(): void
    {
        $site = $this->factory->site([], ['www.example.com']);
        $this->db->insert('rollup_dirty', [
            'site_id' => $site->id(),
            'day' => $this->clock->now()->modify('-20 months')->format('Y-m-d'),
            'first_marked_at' => $this->clock->now()->format('Y-m-d H:i:s.v'),
            'marked_at' => $this->clock->now()->format('Y-m-d H:i:s.v'),
        ]);
        $purger = $this->service(RetentionPurger::class);
        self::assertSame(1, $purger->dirtyDaysBeforeCutoff());
    }

    public function testOperationalTablesHaveTheirOwnWindows(): void
    {
        $user = $this->factory->user();
        $this->db->insert('auth_sessions', [
            'id' => random_bytes(32),
            'user_id' => $user->id(),
            'state' => 'active',
            'csrf_secret' => 'x',
            'created_at' => '2025-01-01 00:00:00',
            'last_seen_at' => '2025-01-01 00:00:00',
            'idle_expires_at' => '2025-01-02 00:00:00',
            'absolute_expires_at' => '2025-02-01 00:00:00',
        ], ['id' => \Doctrine\DBAL\ParameterType::BINARY]);
        $this->db->insert('job_runs', ['job' => 'rollup:run', 'started_at' => '2025-01-01 00:00:00.000', 'status' => 'succeeded']);
        $this->db->insert('audit_log', ['occurred_at' => '2023-01-01 00:00:00', 'actor_type' => 'system', 'action' => 'old.entry', 'metadata' => '{}']);

        $stats = $this->service(RetentionPurger::class)->purge();

        self::assertSame(1, $stats['auth_sessions']);
        self::assertSame(1, $stats['job_runs']);
        self::assertSame(1, $stats['audit_log']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM auth_sessions'));
    }
}
