<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Reporting;

use Analytics\Reporting\Application\ReportQueryFactory;
use Analytics\Reporting\Application\ReportService;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

/**
 * `rollup:rebuild --recompute-days` after a time zone change must keep an event on the day of the
 * visit it belongs to (the ingest-time rule), and rebuild every day a row moved out of or into.
 */
final class RollupRebuildTimezoneTest extends HttpTestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['timezone' => 'UTC', 'cookieLevelEnabled' => true], ['site.test']);
    }

    public function testRecomputingLocalDaysKeepsEventsWithTheirVisit(): void
    {
        // In UTC everything happens on the 17th; in Europe/Rome (+02:00) midnight falls between the
        // two pageviews of the first visit, and the second visit moves to the 18th entirely.
        $this->pageviews([
            ['2026-09-17 21:50:00', '/a'],
            ['2026-09-17 22:10:00', '/b'],
        ]);
        $this->pageviews([['2026-09-17 23:30:00', '/c']]);

        $runner = $this->service(RollupRunner::class);
        $runner->runDirty($this->site->id());
        self::assertSame(['2026-09-17' => 3], $this->eventDays());

        $moved = $this->rebuildIn('Europe/Rome', '2026-09-17', '2026-09-17');

        self::assertSame(1, $moved['moved_visits'], 'only the visit that started after the new midnight moves');
        self::assertSame(1, $moved['moved_events'], 'the straddling visit keeps both of its pageviews');
        self::assertSame(['2026-09-17' => 2, '2026-09-18' => 1], $this->eventDays());
        $this->assertRawDataIsConsistent();

        // The visit is counted once, on the day it started, and its exit page is attributed.
        $pages = $this->pagesReport(true);
        self::assertSame([
            '/a' => ['pageviews' => 1, 'visits' => 1, 'entries' => 1, 'exits' => 0],
            '/b' => ['pageviews' => 1, 'visits' => 1, 'entries' => 0, 'exits' => 1],
            '/c' => ['pageviews' => 1, 'visits' => 1, 'entries' => 1, 'exits' => 1],
        ], $pages);
        self::assertSame($pages, $this->pagesReport(false), 'rollup and raw disagree after the rebuild');
    }

    /** A row that moves back before the requested range must still leave a rebuilt rollup behind. */
    public function testTheRebuiltRangeCoversEveryDayARowMovesTo(): void
    {
        $this->site->timezone = 'Europe/Rome';
        $this->em->flush();
        $this->clearCaches();
        $this->pageviews([['2026-09-17 23:30:00', '/c']]);
        $this->service(RollupRunner::class)->runDirty($this->site->id());
        self::assertSame(['2026-09-18' => 1], $this->eventDays());

        // Back to UTC the row moves to the 17th, a day the requested range does not contain.
        $moved = $this->rebuildIn('UTC', '2026-09-18', '2026-09-18');

        self::assertSame(1, $moved['moved_events']);
        self::assertSame(['2026-09-17' => 1], $this->eventDays());
        $this->assertRawDataIsConsistent();
        self::assertSame(0, Types::int($this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ?', [$this->site->id()])));
        self::assertSame(
            ['/c' => ['pageviews' => 1, 'visits' => 1, 'entries' => 1, 'exits' => 1]],
            $this->pagesReport(false),
            'the day the row moved to was not rebuilt',
        );
    }

    /** @param list<array{0: string, 1: string}> $pageviews time (UTC) and path of one visit */
    private function pageviews(array $pageviews): void
    {
        $vid = Payloads::id22();
        $sid = Payloads::id22();
        $previous = null;
        foreach ($pageviews as [$at, $path]) {
            $this->clock->modify($at);
            $this->collect(Payloads::batch(
                $this->site->publicKey,
                [Payloads::pageview('https://www.site.test' . $path, $previous)],
                'c',
                ['vid' => $vid, 'sid' => $sid, 'cv' => 1],
            ));
            $previous = 'https://www.site.test' . $path;
        }
    }

    /** @return array{days: int, moved_events: int, moved_visits: int} */
    private function rebuildIn(string $timezone, string $from, string $to): array
    {
        $this->site->timezone = $timezone;
        $this->em->flush();
        $this->clearCaches();

        return $this->service(RollupRunner::class)->rebuild(
            $this->site->id(),
            new \DateTimeImmutable($from),
            new \DateTimeImmutable($to),
            true,
        );
    }

    /** @return array<string, int> local day => number of events */
    private function eventDays(): array
    {
        $rows = $this->db->fetchAllKeyValue('SELECT local_day, COUNT(*) FROM events_raw WHERE site_id = ? GROUP BY local_day ORDER BY local_day', [$this->site->id()]);

        return array_map(intval(...), $rows);
    }

    /** Every row that belongs to a visit must carry the visit's day. */
    private function assertRawDataIsConsistent(): void
    {
        $site = $this->site->id();
        self::assertSame(0, Types::int($this->db->fetchOne(
            'SELECT COUNT(*) FROM events_raw e LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day
              WHERE e.site_id = ? AND e.visit_id IS NOT NULL AND v.id IS NULL',
            [$site],
        )), 'an event points at a visit that does not exist on its day');

        foreach (['visit_lookup', 'attribution_touches'] as $table) {
            self::assertGreaterThan(0, Types::int($this->db->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE site_id = ?', [$site])), $table . ' has no row to check');
            self::assertSame(0, Types::int($this->db->fetchOne(
                'SELECT COUNT(*) FROM ' . $table . ' l JOIN visits v ON v.site_id = l.site_id AND v.id = l.visit_id WHERE l.site_id = ? AND l.visit_day <> v.local_day',
                [$site],
            )), $table . '.visit_day did not follow the visit');
        }
    }

    /** @return array<string, array<string, int>> path => metrics */
    private function pagesReport(bool $forceRaw): array
    {
        $this->clearCaches();
        $query = $this->service(ReportQueryFactory::class)->create(
            SiteSnapshot::fromSite($this->site),
            ['period' => 'custom', 'from' => '2026-09-16', 'to' => '2026-09-19', 'limit' => '100'],
            [],
            $forceRaw,
        );
        $result = $this->service(ReportService::class)->run('pages', $query);
        self::assertSame($forceRaw ? 'raw' : 'rollup', $result['meta']['source']);
        $rows = $result['data']['rows'];
        \assert(\is_array($rows));
        $byPath = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            $byPath[Types::string($row['path'])] = [
                'pageviews' => Types::int($row['pageviews']),
                'visits' => Types::int($row['visits']),
                'entries' => Types::int($row['entries']),
                'exits' => Types::int($row['exits']),
            ];
        }
        ksort($byPath);

        return $byPath;
    }
}
