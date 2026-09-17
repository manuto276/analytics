<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Console;

use Analytics\Shared\Doctrine\Partitioning;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\ConsoleTestCase;
use Analytics\Tracking\Application\Seeder;
use Symfony\Component\Console\Command\Command;

/**
 * Commands that change the schema or delete data run outside the per-test transaction.
 */
final class MaintenanceCommandsTest extends ConsoleTestCase
{
    protected bool $useTransaction = false;

    public function testPartitionsMaintainCreatesFutureMonths(): void
    {
        $output = $this->console('partitions:maintain', ['--ahead' => '6']);
        self::assertStringContainsString('partitions:maintain succeeded', $output);

        $partitions = $this->service(\Analytics\Retention\Application\PartitionManager::class);
        $today = $this->clock->now();
        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            $names = array_keys($partitions->partitions($table));
            for ($i = 0; $i <= 6; ++$i) {
                self::assertContains(Partitioning::partitionName($today->modify('+' . $i . ' months')), $names, $table);
            }
        }

        $again = $this->console('partitions:maintain');
        self::assertStringContainsString('"events_raw":[]', $again, 'nothing new to create');

        // The catch-all partition may already have been split (or dropped) by another test:
        // aim two months below the oldest bound that exists now.
        $bounds = array_values(array_filter($partitions->partitions('events_raw'), static fn(string $bound): bool => $bound !== 'MAXVALUE'));
        self::assertNotSame([], $bounds);
        $target = new \DateTimeImmutable($bounds[0], new \DateTimeZone('UTC'))->modify('-2 months');
        $past = $this->console('partitions:maintain', ['--past-from' => $target->format('Y-m-d')]);
        self::assertStringContainsString(Partitioning::partitionName($target), $past);
        self::assertArrayHasKey(Partitioning::partitionName($target), $partitions->partitions('visits'));
        $this->console('partitions:maintain', ['--past-from' => 'nope'], Command::INVALID);
    }

    public function testRetentionPurgeRefusesDirtyDaysAndThenCleansUp(): void
    {
        $site = $this->factory->site([], ['www.example.com']);
        $this->service(Seeder::class)->seed(SiteSnapshot::fromSite($site), $this->clock->now()->modify('-20 months'), 1, 2, 3);

        $dryRun = $this->console('retention:purge', ['--dry-run' => true]);
        self::assertStringContainsString('"events_raw"', $dryRun);
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw'));

        $refused = $this->console('retention:purge', [], Command::FAILURE);
        self::assertStringContainsString('still need rollup:run', $refused);

        $this->console('rollup:run');
        $output = $this->console('retention:purge');
        self::assertStringContainsString('retention:purge succeeded', $output);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw'));
        self::assertGreaterThan(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_overview_daily'), 'rollups survive');

        $forced = $this->console('retention:purge', ['--force' => true]);
        self::assertStringContainsString('succeeded', $forced);
    }
}
