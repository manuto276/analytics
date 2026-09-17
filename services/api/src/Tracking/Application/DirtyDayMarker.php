<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Doctrine\DBAL\Connection;

/**
 * Marks (site, local day) pairs whose raw data changed so rollup:run rebuilds them.
 * first_marked_at measures rollup lag; marked_at lets the runner detect changes made during a build.
 */
final class DirtyDayMarker
{
    public static function mark(Connection $connection, int $siteId, string $day, \DateTimeImmutable $now): void
    {
        $ts = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        $connection->executeStatement(
            'INSERT INTO rollup_dirty (site_id, day, first_marked_at, marked_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE marked_at = GREATEST(marked_at, VALUES(marked_at))',
            [$siteId, $day, $ts, $ts],
        );
    }
}
