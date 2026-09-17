<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Reporting;

use Analytics\Reporting\Application\JobsStatus;
use Analytics\Reporting\Application\ReportQueryFactory;
use Analytics\Reporting\Application\ReportService;
use Analytics\Reporting\Application\Rollup\RollupRunFailed;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Shared\Jobs\JobRunner;
use Analytics\Shared\Types;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tests\Support\Payloads;
use Analytics\Tracking\Application\CollectContext;
use Analytics\Tracking\Application\CollectService;
use Analytics\Tracking\Application\DirtyDayMarker;
use Doctrine\DBAL\ParameterType;

/**
 * Event props are read out of stored JSON by building a path from the key, so a key can only ever
 * be data — never part of the query — and a key that cannot be rolled up must not block other days.
 */
final class EventPropsRollupTest extends IntegrationTestCase
{
    private const string DAY = '2026-09-17';

    /** A build that fails leaves the shared transaction rollback-only, so these tests own the schema. */
    protected bool $useTransaction = false;

    public function testPropKeysWithQuotesAndBackslashesAreRolledUpAndQueryable(): void
    {
        $site = $this->factory->site([], ['www.example.com']);
        $props = ['we"ird' => 'quoted', 'back\\slash' => 'escaped', 'both\\"x' => 'mixed', 'plain' => 'ordinary'];
        ksort($props);
        $this->storeEvent($site, 'signup_click', $props);
        $this->build($site);

        $stored = $this->db->fetchAllKeyValue(
            "SELECT prop_key, prop_value FROM rollup_events_daily WHERE site_id = ? AND prop_key <> '' ORDER BY prop_key",
            [$site->id()],
        );
        ksort($stored);
        self::assertSame($props, $stored, 'every key must survive the rollup verbatim');

        foreach ([false, true] as $forceRaw) {
            $this->clearCaches();
            $rows = $this->report($site, 'event-props', ['event' => 'signup_click'], $forceRaw);
            $byKey = [];
            foreach ($rows as $row) {
                \assert(\is_array($row));
                $byKey[Types::string($row['prop_key'])] = Types::string($row['prop_value']);
                self::assertSame(1, Types::int($row['occurrences']));
            }
            ksort($byKey);
            self::assertSame($props, $byKey, ($forceRaw ? 'raw' : 'rollup') . ' lost a key');
        }
    }

    /**
     * A key that cannot be stored (it does not fit the rollup column) fails that one day. It must
     * leave the row dirty, let every other site finish, and still report the run as failed.
     */
    public function testAPoisonedDayDoesNotStopTheRollupsOfOtherSites(): void
    {
        $poisoned = $this->factory->site([], ['poisoned.example.com']);
        $healthy = $this->factory->site([], ['healthy.example.com']);
        $this->storeEvent($poisoned, 'signup_click', [str_repeat('k', 40) => 'too long to store']);
        $this->storeEvent($healthy, 'signup_click', ['plan' => 'pro']);
        // The poisoned day is marked first, so the runner reaches it before the healthy one.
        DirtyDayMarker::mark($this->db, $poisoned->id(), self::DAY, $this->clock->now()->modify('-1 hour'));
        DirtyDayMarker::mark($this->db, $healthy->id(), self::DAY, $this->clock->now());

        $runner = $this->service(RollupRunner::class);
        try {
            $runner->runDirty();
            self::fail('the run must be reported as failed');
        } catch (RollupRunFailed $e) {
            self::assertSame(['days' => 1, 'sites' => 1, 'failed' => 1], $e->stats);
            self::assertSame([['site' => $poisoned->id(), 'day' => self::DAY]], $e->failures);
            self::assertStringContainsString('site ' . $poisoned->id() . ' day ' . self::DAY, $e->getMessage());
        }

        self::assertGreaterThan(0, $this->rollupRows($healthy), 'the healthy site was not rolled up');
        self::assertSame(0, $this->rollupRows($poisoned));
        self::assertSame([self::DAY], $this->db->fetchFirstColumn('SELECT day FROM rollup_dirty WHERE site_id = ?', [$poisoned->id()]), 'the failing day must stay dirty');
        self::assertSame([], $this->db->fetchFirstColumn('SELECT day FROM rollup_dirty WHERE site_id = ?', [$healthy->id()]));
    }

    /**
     * The counts a failing run did produce have to reach the operator. `JobRunner` used to write an
     * empty `stats` on a failed job, which is why the rollup runner had to smuggle them into the
     * exception message; now the failure carries them and `jobs:status` shows them.
     */
    public function testAFailedRunStillRecordsWhatItManagedToBuild(): void
    {
        $poisoned = $this->factory->site([], ['poisoned.example.com']);
        $healthy = $this->factory->site([], ['healthy.example.com']);
        $this->storeEvent($poisoned, 'signup_click', [str_repeat('k', 40) => 'too long to store']);
        $this->storeEvent($healthy, 'signup_click', ['plan' => 'pro']);
        DirtyDayMarker::mark($this->db, $poisoned->id(), self::DAY, $this->clock->now()->modify('-1 hour'));
        DirtyDayMarker::mark($this->db, $healthy->id(), self::DAY, $this->clock->now());

        $runner = $this->service(RollupRunner::class);
        $result = $this->service(JobRunner::class)->run('rollup:run', fn(): array => $runner->runDirty());

        self::assertSame('failed', $result['status']);
        self::assertSame(['days' => 1, 'sites' => 1, 'failed' => 1], $result['stats'], 'the partial counts must survive the failure');
        self::assertStringContainsString('day ' . self::DAY, Types::string($result['message']));

        $row = $this->db->fetchAssociative('SELECT status, stats FROM job_runs WHERE job = ? ORDER BY id DESC LIMIT 1', ['rollup:run']);
        \assert(\is_array($row));
        self::assertSame('failed', Types::string($row['status']));
        self::assertSame(['days' => 1, 'sites' => 1, 'failed' => 1], json_decode(Types::string($row['stats']), true));

        $jobs = $this->service(JobsStatus::class)->snapshot()['jobs'];
        \assert(\is_array($jobs));
        $rollup = array_values(array_filter($jobs, static fn(mixed $job): bool => \is_array($job) && ($job['job'] ?? null) === 'rollup:run'));
        self::assertSame(['days' => 1, 'sites' => 1, 'failed' => 1], $rollup[0]['last_stats'] ?? null, 'jobs:status must show the partial counts');
    }

    /**
     * The PII scrubber runs on a property key *after* the parser has validated it, so what is stored
     * is not the key the allow-list accepted. It must still be a key the rollup can insert, or that
     * one (site, day) can never be built again.
     */
    public function testAKeyRewrittenByTheScrubberIsStoredAndRollsUp(): void
    {
        $site = $this->factory->site([], ['www.example.com']);
        $result = $this->service(CollectService::class)->collect(
            Payloads::batch($site->publicKey, [
                Payloads::event('signup_click', ['user1234567890' => 'pro', 'plan' => 'free'], 'https://www.example.com/pricing'),
            ]),
            new CollectContext(null, Payloads::CHROME_UA, 'en-GB', 'https://www.example.com', '', false, false),
        );
        self::assertSame(1, $result['accepted'], 'rewriting the key must not cost the event');

        $stored = json_decode(Types::string($this->db->fetchOne("SELECT props FROM events_raw WHERE site_id = ? AND type = 'ev'", [$site->id()])), true);
        \assert(\is_array($stored));
        ksort($stored);
        self::assertSame(['plan' => 'free', 'user[number]' => 'pro'], $stored, 'the digits are scrubbed out of the key');

        // Would throw RollupRunFailed if the rewritten key did not fit rollup_events_daily.prop_key.
        $this->build($site);

        $rolled = $this->db->fetchAllKeyValue(
            "SELECT prop_key, prop_value FROM rollup_events_daily WHERE site_id = ? AND prop_key <> '' ORDER BY prop_key",
            [$site->id()],
        );
        self::assertSame(['plan' => 'free', 'user[number]' => 'pro'], $rolled);
        self::assertSame([], $this->db->fetchFirstColumn('SELECT day FROM rollup_dirty WHERE site_id = ?', [$site->id()]), 'the day must not stay dirty');
    }

    /** @param array<string, string> $props */
    private function storeEvent(Site $site, string $name, array $props): void
    {
        $this->db->insert('events_raw', [
            'local_day' => self::DAY,
            'site_id' => $site->id(),
            'event_uid' => random_bytes(12),
            'occurred_at' => self::DAY . ' 12:00:00.000',
            'received_at' => self::DAY . ' 12:00:00.000',
            'level' => 'b',
            'type' => 'ev',
            'name' => $name,
            'is_entry' => 0,
            'visitor_hash' => 1234567890,
            'host' => 'www.example.com',
            'path' => '/',
            'page_hash' => random_bytes(8),
            'channel' => 'direct',
            'props' => json_encode($props, \JSON_THROW_ON_ERROR),
        ], ['event_uid' => ParameterType::BINARY, 'page_hash' => ParameterType::BINARY]);
    }

    private function build(Site $site): void
    {
        DirtyDayMarker::mark($this->db, $site->id(), self::DAY, $this->clock->now());
        $this->service(RollupRunner::class)->runDirty($site->id());
    }

    private function rollupRows(Site $site): int
    {
        return Types::int($this->db->fetchOne('SELECT COUNT(*) FROM rollup_events_daily WHERE site_id = ?', [$site->id()]));
    }

    /**
     * @param array<string, string> $options
     *
     * @return list<mixed>
     */
    private function report(Site $site, string $report, array $options, bool $forceRaw): array
    {
        $query = $this->service(ReportQueryFactory::class)->create(
            SiteSnapshot::fromSite($site),
            // A single-day range would default to the hour interval, which is always raw.
            ['period' => 'custom', 'from' => '2026-09-15', 'to' => '2026-09-19', 'limit' => '100'],
            $options,
            $forceRaw,
        );
        $result = $this->service(ReportService::class)->run($report, $query);
        self::assertSame($forceRaw ? 'raw' : 'rollup', $result['meta']['source']);
        $rows = $result['data']['rows'];
        \assert(\is_array($rows));

        return array_values($rows);
    }
}
