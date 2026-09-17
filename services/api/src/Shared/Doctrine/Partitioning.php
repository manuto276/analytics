<?php

declare(strict_types=1);

namespace Analytics\Shared\Doctrine;

/**
 * Monthly RANGE COLUMNS(local_day) partitioning helpers.
 * Partition pYYYYMM holds days of that month; p_old holds everything earlier than the first month; pmax is the catch-all.
 */
final class Partitioning
{
    public const array PARTITIONED_TABLES = ['events_raw', 'visits', 'consent_stat_uids'];

    /** Set from Settings when the container is built; migrations run outside the container's reach. */
    private static ?bool $enabled = null;

    public static function configure(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        if (self::$enabled !== null) {
            return self::$enabled;
        }
        $flag = $_ENV['DB_PARTITIONING'] ?? $_SERVER['DB_PARTITIONING'] ?? getenv('DB_PARTITIONING');

        return filter_var(\is_string($flag) && $flag !== '' ? $flag : 'true', \FILTER_VALIDATE_BOOL);
    }

    public static function partitionName(\DateTimeImmutable $month): string
    {
        return 'p' . $month->format('Ym');
    }

    public static function monthStart(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day->format('Y-m-01'), new \DateTimeZone('UTC'));
    }

    /** "PARTITION BY RANGE COLUMNS(local_day) (...)" covering from the current month to +$ahead months. */
    public static function createClause(\DateTimeImmutable $today, int $ahead = 3): string
    {
        $start = self::monthStart($today);
        $parts = [\sprintf("PARTITION p_old VALUES LESS THAN ('%s')", $start->format('Y-m-d'))];
        for ($i = 0; $i <= $ahead; ++$i) {
            $month = $start->modify('+' . $i . ' month');
            $parts[] = self::definition($month);
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        return "PARTITION BY RANGE COLUMNS(local_day) (\n  " . implode(",\n  ", $parts) . "\n)";
    }

    public static function definition(\DateTimeImmutable $month): string
    {
        return \sprintf("PARTITION %s VALUES LESS THAN ('%s')", self::partitionName($month), self::monthStart($month)->modify('+1 month')->format('Y-m-d'));
    }
}
