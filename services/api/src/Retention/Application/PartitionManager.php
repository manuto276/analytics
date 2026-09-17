<?php

declare(strict_types=1);

namespace Analytics\Retention\Application;

use Analytics\Kernel\Settings;
use Analytics\Shared\Doctrine\Partitioning;
use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;

/**
 * Monthly partitions of events_raw and visits: keeps future partitions ready and drops whole
 * months when retention passes. With DB_PARTITIONING=false everything falls back to chunked deletes.
 */
final readonly class PartitionManager
{
    public function __construct(private Connection $connection, private Settings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->dbPartitioning;
    }

    /** @return array<string, string> partition name => upper bound (exclusive), ordered */
    public function partitions(string $table): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT PARTITION_NAME AS name, PARTITION_DESCRIPTION AS upper_bound
               FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
              ORDER BY PARTITION_ORDINAL_POSITION',
            [$table],
        );
        $partitions = [];
        foreach ($rows as $row) {
            $partitions[Types::string($row['name'])] = trim(Types::string($row['upper_bound']), "'");
        }

        return $partitions;
    }

    public function isPartitioned(string $table): bool
    {
        return $this->partitions($table) !== [];
    }

    /**
     * Ensures partitions exist for the current month and the next $ahead months.
     *
     * @return array<string, list<string>> table => created partitions
     */
    public function maintain(\DateTimeImmutable $today, int $ahead = 3): array
    {
        $created = [];
        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            $created[$table] = [];
            if (!$this->isPartitioned($table)) {
                continue;
            }
            $existing = $this->partitions($table);
            $definitions = [];
            $start = Partitioning::monthStart($today);
            for ($i = 0; $i <= $ahead; ++$i) {
                $month = $start->modify('+' . $i . ' month');
                $name = Partitioning::partitionName($month);
                if (!isset($existing[$name])) {
                    $definitions[] = Partitioning::definition($month);
                }
            }
            if ($definitions === []) {
                continue;
            }
            $this->connection->executeStatement(\sprintf(
                'ALTER TABLE %s REORGANIZE PARTITION pmax INTO (%s, PARTITION pmax VALUES LESS THAN (MAXVALUE))',
                $table,
                implode(', ', $definitions),
            ));
            $created[$table] = array_map(static fn(string $definition): string => explode(' ', $definition)[1], $definitions);
        }

        return $created;
    }

    /**
     * Splits the lowest (catch-all) partition into monthly partitions starting at $oldest, so
     * imported or backfilled history can also be dropped a month at a time.
     *
     * @return array<string, list<string>> table => created partitions
     */
    public function ensurePastMonths(\DateTimeImmutable $oldest): array
    {
        $created = [];
        foreach (Partitioning::PARTITIONED_TABLES as $table) {
            $created[$table] = [];
            $partitions = $this->partitions($table);
            if ($partitions === []) {
                continue;
            }
            // The lowest partition covers everything below its bound: split it into months.
            $lowestName = (string) array_key_first($partitions);
            $lowestBound = $partitions[$lowestName];
            if ($lowestBound === 'MAXVALUE') {
                continue;
            }
            $start = Partitioning::monthStart($oldest);
            $end = new \DateTimeImmutable($lowestBound, new \DateTimeZone('UTC'));
            if ($start >= $end) {
                continue;
            }
            $definitions = [];
            for ($month = $start; $month < $end; $month = $month->modify('+1 month')) {
                $definitions[] = Partitioning::definition($month);
                $created[$table][] = Partitioning::partitionName($month);
            }
            // Keep the lowest partition itself as the catch-all below the first new month.
            array_unshift($definitions, \sprintf("PARTITION %s VALUES LESS THAN ('%s')", $lowestName, $start->format('Y-m-d')));
            $this->connection->executeStatement(\sprintf('ALTER TABLE %s REORGANIZE PARTITION %s INTO (%s)', $table, $lowestName, implode(', ', $definitions)));
        }

        return $created;
    }

    /**
     * Drops every partition whose whole range is before $cutoff (exclusive).
     *
     * @return list<string> dropped partitions ("table.partition")
     */
    public function dropBefore(string $table, \DateTimeImmutable $cutoff): array
    {
        $dropped = [];
        foreach ($this->partitions($table) as $name => $upperBound) {
            if ($name === 'pmax' || $upperBound === 'MAXVALUE') {
                continue;
            }
            if ($upperBound <= $cutoff->format('Y-m-d')) {
                $this->connection->executeStatement(\sprintf('ALTER TABLE %s DROP PARTITION %s', $table, $name));
                $dropped[] = $table . '.' . $name;
            }
        }

        return $dropped;
    }

    /** Deletes rows in chunks (used when partitioning is off, or for the catch-all partition). */
    public function deleteBefore(string $table, string $column, \DateTimeImmutable $cutoff, int $chunk = 5000): int
    {
        $deleted = 0;
        do {
            $affected = (int) $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE %s < ? LIMIT %d', $table, $column, $chunk),
                [$cutoff->format('Y-m-d')],
            );
            $deleted += $affected;
        } while ($affected === $chunk);

        return $deleted;
    }
}
