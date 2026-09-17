<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Rollup;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Tracking\Application\DirtyDayMarker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

final readonly class RollupRunner
{
    public const int BATCH = 500;

    public function __construct(
        private Connection $connection,
        private RollupBuilder $builder,
        private SiteRepository $sites,
        private ClockInterface $clock,
    ) {}

    /**
     * Rebuilds dirty days (oldest first).
     *
     * @return array{days: int, sites: int}
     */
    public function runDirty(?int $siteId = null, int $limit = self::BATCH): array
    {
        $sql = 'SELECT site_id, day, marked_at FROM rollup_dirty' . ($siteId !== null ? ' WHERE site_id = ?' : '') . ' ORDER BY first_marked_at LIMIT ' . max(1, $limit);
        $rows = $this->connection->fetchAllAssociative($sql, $siteId !== null ? [$siteId] : []);
        $touched = [];
        $days = 0;
        foreach ($rows as $row) {
            $site = $this->sites->snapshot(Types::int($row['site_id']));
            $day = Types::string($row['day']);
            if ($site === null) {
                $this->connection->executeStatement('DELETE FROM rollup_dirty WHERE site_id = ? AND day = ?', [$row['site_id'], $day]);
                continue;
            }
            $this->connection->transactional(function () use ($site, $day, $row): void {
                $this->builder->build($site, $day);
                $this->connection->executeStatement(
                    'DELETE FROM rollup_dirty WHERE site_id = ? AND day = ? AND marked_at <= ?',
                    [$site->id, $day, Types::string($row['marked_at'])],
                    [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
                );
            });
            $touched[$site->id] = true;
            ++$days;
        }
        foreach (array_keys($touched) as $id) {
            $this->connection->executeStatement('UPDATE sites SET rollup_version = rollup_version + 1 WHERE id = ?', [$id]);
        }

        return ['days' => $days, 'sites' => \count($touched)];
    }

    /**
     * Marks a range dirty and rebuilds it. With $recomputeLocalDays the local_day of raw rows is
     * recalculated from occurred_at in the site's current time zone first (after a time zone change).
     *
     * @return array{days: int, moved_events: int, moved_visits: int}
     */
    public function rebuild(int $siteId, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $recomputeLocalDays = false): array
    {
        $site = $this->sites->snapshot($siteId) ?? throw new \InvalidArgumentException('Site not found.');
        $moved = ['events' => 0, 'visits' => 0];
        if ($recomputeLocalDays) {
            $moved = $this->recomputeLocalDays($siteId, $site->timezone(), $from, $to);
        }
        $now = $this->clock->now();
        $allDays = [];
        for ($day = $from; $day <= $to->modify('+1 day'); $day = $day->modify('+1 day')) {
            DirtyDayMarker::mark($this->connection, $siteId, $day->format('Y-m-d'), $now);
            $allDays[] = $day->format('Y-m-d');
        }
        $this->builder->clearRollupsForDays($siteId, $allDays);
        $total = 0;
        do {
            $result = $this->runDirty($siteId);
            $total += $result['days'];
        } while ($result['days'] > 0);

        return ['days' => $total, 'moved_events' => $moved['events'], 'moved_visits' => $moved['visits']];
    }

    /** @return array{events: int, visits: int} */
    private function recomputeLocalDays(int $siteId, \DateTimeZone $tz, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $moved = ['events' => 0, 'visits' => 0];
        $lower = $from->modify('-1 day')->format('Y-m-d');
        $upper = $to->modify('+1 day')->format('Y-m-d');
        foreach (['events_raw' => ['occurred_at', 'events'], 'visits' => ['started_at', 'visits']] as $table => [$column, $key]) {
            $lastId = 0;
            do {
                $rows = $this->connection->fetchAllAssociative(
                    "SELECT id, local_day, {$column} AS at FROM {$table} WHERE site_id = ? AND local_day BETWEEN ? AND ? AND id > ? ORDER BY id LIMIT 1000",
                    [$siteId, $lower, $upper, $lastId],
                );
                foreach ($rows as $row) {
                    $lastId = Types::int($row['id']);
                    $local = new \DateTimeImmutable(Types::string($row['at']), new \DateTimeZone('UTC'))->setTimezone($tz)->format('Y-m-d');
                    if ($local !== $row['local_day']) {
                        $this->connection->executeStatement("UPDATE {$table} SET local_day = ? WHERE id = ? AND local_day = ?", [$local, $row['id'], $row['local_day']]);
                        if ($table === 'visits') {
                            $this->connection->executeStatement('UPDATE visit_lookup SET visit_day = ? WHERE site_id = ? AND visit_id = ?', [$local, $siteId, $row['id']]);
                        }
                        ++$moved[$key];
                    }
                }
            } while (\count($rows) === 1000);
        }

        return $moved;
    }
}
