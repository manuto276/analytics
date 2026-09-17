<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Rollup;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteRepository;
use Analytics\Tracking\Application\DirtyDayMarker;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class RollupRunner
{
    public const int BATCH = 500;
    private const int SCAN_CHUNK = 1000;

    public function __construct(
        private Connection $connection,
        private RollupBuilder $builder,
        private SiteRepository $sites,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Rebuilds dirty days (oldest first).
     *
     * A day whose build throws is left dirty and the run carries on, so one poisoned day cannot
     * block every other site forever; the failures are summarised in a RollupRunFailed at the end
     * so the job is still recorded as failed.
     *
     * @return array{days: int, sites: int, failed: int}
     *
     * @throws RollupRunFailed when at least one day could not be built
     */
    public function runDirty(?int $siteId = null, int $limit = self::BATCH): array
    {
        $sql = 'SELECT site_id, day, marked_at FROM rollup_dirty' . ($siteId !== null ? ' WHERE site_id = ?' : '') . ' ORDER BY first_marked_at LIMIT ' . max(1, $limit);
        $rows = $this->connection->fetchAllAssociative($sql, $siteId !== null ? [$siteId] : []);
        $touched = [];
        $days = 0;
        $failures = [];
        foreach ($rows as $row) {
            $site = $this->sites->snapshot(Types::int($row['site_id']));
            $day = Types::string($row['day']);
            if ($site === null) {
                $this->connection->executeStatement('DELETE FROM rollup_dirty WHERE site_id = ? AND day = ?', [$row['site_id'], $day]);
                continue;
            }
            try {
                $this->connection->transactional(function () use ($site, $day, $row): void {
                    $this->builder->build($site, $day);
                    $this->connection->executeStatement(
                        'DELETE FROM rollup_dirty WHERE site_id = ? AND day = ? AND marked_at <= ?',
                        [$site->id, $day, Types::string($row['marked_at'])],
                        [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
                    );
                });
            } catch (\Throwable $e) {
                $failures[] = ['site' => $site->id, 'day' => $day];
                $this->logger->error('Rollup build failed', [
                    'site_id' => $site->id,
                    'day' => $day,
                    'error' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 1000),
                ]);
                continue;
            }
            $touched[$site->id] = true;
            ++$days;
        }
        foreach (array_keys($touched) as $id) {
            $this->connection->executeStatement('UPDATE sites SET rollup_version = rollup_version + 1 WHERE id = ?', [$id]);
        }

        $stats = ['days' => $days, 'sites' => \count($touched), 'failed' => \count($failures)];
        if ($failures !== []) {
            throw new RollupRunFailed($stats, $failures);
        }

        return $stats;
    }

    /**
     * Marks a range dirty and rebuilds it. With $recomputeLocalDays the local_day of raw rows is
     * recalculated from occurred_at in the site's current time zone first (after a time zone change);
     * every day a row moved out of or into is rebuilt too.
     *
     * @return array{days: int, moved_events: int, moved_visits: int}
     */
    public function rebuild(int $siteId, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $recomputeLocalDays = false): array
    {
        $site = $this->sites->snapshot($siteId) ?? throw new \InvalidArgumentException('Site not found.');
        $moved = ['events' => 0, 'visits' => 0, 'days' => []];
        if ($recomputeLocalDays) {
            $moved = $this->recomputeLocalDays($siteId, $site->timezone(), $from, $to);
        }
        $now = $this->clock->now();
        $allDays = [];
        for ($day = $from; $day <= $to->modify('+1 day'); $day = $day->modify('+1 day')) {
            $allDays[$day->format('Y-m-d')] = true;
        }
        foreach ($moved['days'] as $day) {
            $allDays[$day] = true;
        }
        $allDays = array_keys($allDays);
        sort($allDays);
        foreach ($allDays as $day) {
            DirtyDayMarker::mark($this->connection, $siteId, $day, $now);
        }
        $this->builder->clearRollupsForDays($siteId, $allDays);
        $total = 0;
        do {
            $result = $this->runDirty($siteId);
            $total += $result['days'];
        } while ($result['days'] > 0);

        return ['days' => $total, 'moved_events' => $moved['events'], 'moved_visits' => $moved['visits']];
    }

    /**
     * Recomputes local_day for the raw rows around the range.
     *
     * Visits move first, from started_at. Events then follow: an event that belongs to a visit
     * carries the visit's day (that is the ingest-time rule, see IngestBatchHandler::resolveVisit()),
     * so the (visit_id, local_day) pair every reporting join relies on survives; a standalone event
     * keeps its own day recomputed from occurred_at. visit_lookup.visit_day and
     * attribution_touches.visit_day follow the visit.
     *
     * @return array{events: int, visits: int, days: list<string>} days: every day a row left or entered
     */
    private function recomputeLocalDays(int $siteId, \DateTimeZone $tz, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $moved = ['events' => 0, 'visits' => 0];
        /** @var array<string, true> $days */
        $days = [];
        $lower = $from->modify('-1 day')->format('Y-m-d');
        $upper = $to->modify('+1 day')->format('Y-m-d');

        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, local_day, started_at FROM visits WHERE site_id = ? AND local_day BETWEEN ? AND ? AND id > ? ORDER BY id LIMIT ' . self::SCAN_CHUNK,
                [$siteId, $lower, $upper, $lastId],
            );
            foreach ($rows as $row) {
                $lastId = Types::int($row['id']);
                $old = Types::string($row['local_day']);
                $local = self::localDay(Types::string($row['started_at']), $tz);
                if ($local === $old) {
                    continue;
                }
                $this->connection->executeStatement('UPDATE visits SET local_day = ? WHERE id = ? AND local_day = ?', [$local, $lastId, $old]);
                $days[$old] = true;
                $days[$local] = true;
                ++$moved['visits'];
            }
        } while (\count($rows) === self::SCAN_CHUNK);

        if ($moved['visits'] > 0) {
            foreach (['visit_lookup', 'attribution_touches'] as $table) {
                $this->connection->executeStatement(
                    "UPDATE {$table} l JOIN visits v ON v.site_id = l.site_id AND v.id = l.visit_id
                        SET l.visit_day = v.local_day
                      WHERE l.site_id = ? AND l.visit_day BETWEEN ? AND ? AND l.visit_day <> v.local_day",
                    [$siteId, $lower, $upper],
                );
            }
        }

        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, local_day, visit_id, occurred_at FROM events_raw WHERE site_id = ? AND local_day BETWEEN ? AND ? AND id > ? ORDER BY id LIMIT ' . self::SCAN_CHUNK,
                [$siteId, $lower, $upper, $lastId],
            );
            $visitDays = $this->visitDays($siteId, $rows);
            foreach ($rows as $row) {
                $lastId = Types::int($row['id']);
                $old = Types::string($row['local_day']);
                $visitId = Types::nullableInt($row['visit_id']);
                $local = $visitId !== null
                    ? ($visitDays[$visitId] ?? self::localDay(Types::string($row['occurred_at']), $tz))
                    : self::localDay(Types::string($row['occurred_at']), $tz);
                if ($local === $old) {
                    continue;
                }
                $this->connection->executeStatement('UPDATE events_raw SET local_day = ? WHERE id = ? AND local_day = ?', [$local, $lastId, $old]);
                $days[$old] = true;
                $days[$local] = true;
                ++$moved['events'];
            }
        } while (\count($rows) === self::SCAN_CHUNK);

        return ['events' => $moved['events'], 'visits' => $moved['visits'], 'days' => array_keys($days)];
    }

    /**
     * Current local_day of every visit referenced by this batch of events.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, string> visit id => local day
     */
    private function visitDays(int $siteId, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = Types::nullableInt($row['visit_id']);
            if ($id !== null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $found = $this->connection->fetchAllAssociative(
            'SELECT id, local_day FROM visits WHERE site_id = ? AND id IN (?)',
            [$siteId, array_keys($ids)],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        $byId = [];
        foreach ($found as $row) {
            $byId[Types::int($row['id'])] = Types::string($row['local_day']);
        }

        return $byId;
    }

    private static function localDay(string $at, \DateTimeZone $tz): string
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'))->setTimezone($tz)->format('Y-m-d');
    }
}
