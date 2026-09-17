<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Reporting;

use Analytics\Identity\Domain\SiteRole;
use Analytics\Reporting\Application\Rollup\RollupRunner;
use Analytics\Sites\Domain\Site;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class ReportsTest extends HttpTestCase
{
    private Site $site;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['timezone' => 'Europe/Rome', 'contentContactEvents' => ['contact_form']], ['www.site.test']);
        $this->base = '/api/v1/sites/' . $this->site->id() . '/reports';
        $viewer = $this->factory->user();
        $this->factory->grant($viewer, $this->site, SiteRole::Viewer);
        $this->loginAs($viewer);
    }

    /** Two visitors, three visits, four pageviews, one custom event, one engagement event. */
    private function ingest(): void
    {
        $key = $this->site->publicKey;
        $this->clock->modify('2026-09-16 08:00:00');
        // Visitor A: organic search landing, two pages, one event, engagement.
        $this->collect(Payloads::batch($key, [
            Payloads::pageview('https://www.site.test/', 'https://www.google.com/'),
            Payloads::pageview('https://www.site.test/pricing', 'https://www.site.test/'),
            Payloads::event('signup_click', ['plan' => 'pro'], 'https://www.site.test/pricing'),
            Payloads::engagement(20000, 90, 'https://www.site.test/pricing'),
        ]), [], '203.0.113.10');
        // Visitor B: direct single page (bounce), mobile.
        $this->collect(Payloads::batch($key, [
            ['ck' => 'author:7'] + Payloads::pageview('https://www.site.test/blog/post'),
        ]), ['User-Agent' => Payloads::IPHONE_UA], '198.51.100.20');

        // Next day, visitor A returns from a newsletter campaign.
        $this->clock->modify('2026-09-17 09:00:00');
        $this->collect(Payloads::batch($key, [
            Payloads::pageview('https://www.site.test/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=september'),
        ]), [], '203.0.113.10');

        $this->clock->modify('2026-09-17 10:00:00');
        $runner = $this->service(RollupRunner::class);
        $runner->runDirty($this->site->id());
    }

    public function testOverviewAndTimeseries(): void
    {
        $this->ingest();
        $response = $this->get($this->base . '/overview?period=7d&compare=previous_period');
        $data = $this->data($response);
        $metrics = $data['metrics'];

        self::assertSame(3, $metrics['visits']);
        self::assertSame(4, $metrics['pageviews']);
        self::assertSame(3, $metrics['visitors'], 'visitor-days: A on two days, B on one');
        self::assertSame(1, $metrics['events']);
        self::assertEqualsWithDelta(1.33, $metrics['views_per_visit'], 0.01);
        self::assertEqualsWithDelta(0.6667, $metrics['bounce_rate'], 0.0001);
        self::assertSame(6667, $metrics['avg_duration_ms']);
        self::assertSame(0, $metrics['conversions']);
        self::assertSame(0.0, $metrics['consent_rate']);
        self::assertIsArray($data['compare']);
        self::assertSame(0, $data['compare']['visits']);

        $json = $this->json($response);
        self::assertSame('rollup', $json['meta']['source']);
        self::assertSame(['visitors' => true, 'bounce' => true, 'duration' => true], $json['meta']['availability']);
        self::assertSame('Europe/Rome', $json['meta']['timezone']);
        self::assertSame(['from' => '2026-09-11', 'to' => '2026-09-17'], $json['meta']['range']);

        $series = $this->data($this->get($this->base . '/timeseries?period=7d&interval=day'));
        $points = $series['points'];
        self::assertCount(7, $points);
        self::assertSame('2026-09-11', $points[0]['t']);
        self::assertSame(0, $points[0]['visits']);
        self::assertSame(2, $points[5]['visits']);
        self::assertSame(3, $points[5]['pageviews']);
        self::assertSame(1, $points[6]['visits']);
    }

    public function testPagesSourcesTechCountriesEventsContent(): void
    {
        $this->ingest();

        $pages = $this->data($this->get($this->base . '/pages?period=7d'))['rows'];
        self::assertSame([
            ['/pricing', 2, 1],
            ['/', 1, 1],
            ['/blog/post', 1, 1],
        ], array_map(static fn(array $r): array => [$r['path'], $r['pageviews'], $r['entries']], $pages));
        self::assertSame(2, $pages[0]['visits']);

        $entry = $this->data($this->get($this->base . '/pages?period=7d&kind=entry'))['rows'];
        $entryPaths = array_map(static fn(array $r): string => (string) $r['path'], $entry);
        sort($entryPaths);
        self::assertSame(['/', '/blog/post', '/pricing'], $entryPaths);

        $channels = $this->data($this->get($this->base . '/sources?period=7d&group=channel'))['rows'];
        self::assertSame([
            ['direct', 1],
            ['email', 1],
            ['organic_search', 1],
        ], array_map(static fn(array $r): array => [$r['channel'], $r['visits']], $channels), 'ties are broken by the first grouping column');

        $sources = $this->data($this->get($this->base . '/sources?period=7d&group=source'))['rows'];
        self::assertSame([['Google', 1], ['newsletter', 1]], array_map(static fn(array $r): array => [$r['source'], $r['visits']], $sources));

        $campaigns = $this->data($this->get($this->base . '/campaigns?period=7d'))['rows'];
        self::assertSame([['newsletter', 'email', 'september', 1]], array_map(static fn(array $r): array => [$r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['visits']], $campaigns));

        $devices = $this->data($this->get($this->base . '/tech?period=7d&group=device'))['rows'];
        self::assertSame([['desktop', 2], ['mobile', 1]], array_map(static fn(array $r): array => [$r['value'], $r['visits']], $devices));
        $browsers = $this->data($this->get($this->base . '/tech?period=7d&group=browser'))['rows'];
        self::assertSame(['Chrome', 'Mobile Safari'], array_map(static fn(array $r): string => (string) $r['value'], $browsers));

        $countries = $this->data($this->get($this->base . '/countries?period=7d'))['rows'];
        self::assertSame([[null, 3]], array_map(static fn(array $r): array => [$r['country'], $r['visits']], $countries), 'no geo database in tests');

        $events = $this->data($this->get($this->base . '/events?period=7d'))['rows'];
        self::assertSame([['signup_click', 1, 1]], array_map(static fn(array $r): array => [$r['name'], $r['occurrences'], $r['visits']], $events));
        $props = $this->data($this->get($this->base . '/events/signup_click/props?period=7d'))['rows'];
        self::assertSame([['plan', 'pro', 1]], array_map(static fn(array $r): array => [$r['prop_key'], $r['prop_value'], $r['occurrences']], $props));

        $content = $this->data($this->get($this->base . '/content?period=7d'))['rows'];
        self::assertSame([['author:7', 1, 1, 0]], array_map(static fn(array $r): array => [$r['content_key'], $r['pageviews'], $r['visits'], $r['contacts']], $content));
        self::assertSame(['direct' => 1], (array) $content[0]['channels']);
    }

    public function testFiltersSwitchToRawAndNarrowTheResult(): void
    {
        $this->ingest();

        $response = $this->get($this->base . '/overview?period=7d&' . http_build_query(['filter' => ['channel' => ['is' => 'email']]]));
        $json = $this->json($response);
        self::assertSame('raw', $json['meta']['source']);
        self::assertSame(1, $json['data']['metrics']['visits']);
        self::assertSame(1, $json['data']['metrics']['pageviews']);

        $pages = $this->json($this->get($this->base . '/pages?period=7d&' . http_build_query(['filter' => ['page' => ['prefix' => '/blog']]])));
        self::assertSame('rollup', $pages['meta']['source'], 'the pages rollup can filter by page');
        self::assertSame(['/blog/post'], array_map(static fn(array $r): string => (string) $r['path'], $pages['data']['rows']));

        $device = $this->json($this->get($this->base . '/pages?period=7d&' . http_build_query(['filter' => ['device' => ['is' => 'mobile']]])));
        self::assertSame('raw', $device['meta']['source']);
        self::assertSame(['/blog/post'], array_map(static fn(array $r): string => (string) $r['path'], $device['data']['rows']));

        $event = $this->json($this->get($this->base . '/overview?period=7d&' . http_build_query(['filter' => ['event' => ['is' => 'signup_click']]])));
        self::assertSame(1, $event['data']['metrics']['visits'], 'only the visit with that event');
        self::assertSame(2, $event['data']['metrics']['pageviews']);

        $none = $this->json($this->get($this->base . '/overview?period=7d&' . http_build_query(['filter' => ['channel' => ['is' => 'paid_search']]])));
        self::assertSame(0, $none['data']['metrics']['visits']);
    }

    public function testValidationAndPlannerErrors(): void
    {
        $this->assertProblem($this->get($this->base . '/overview?period=nope'), 422);
        $this->assertProblem($this->get($this->base . '/overview?period=custom'), 422);
        $this->assertProblem($this->get($this->base . '/overview?period=custom&from=2026-09-01&to=bad'), 422);
        $this->assertProblem($this->get($this->base . '/overview?period=30d&interval=hour'), 422);
        $this->assertProblem($this->get($this->base . '/pages?sort=wrong'), 422);
        $this->assertProblem($this->get($this->base . '/pages?cursor=zzz'), 422);
        $this->assertProblem($this->get($this->base . '/overview?' . http_build_query(['filter' => ['nope' => ['is' => 'x']]])), 422);
        $this->assertProblem($this->get($this->base . '/overview?' . http_build_query(['filter' => ['channel' => ['like' => 'x']]])), 422);
        $this->assertProblem($this->get($this->base . '/conversions?' . http_build_query(['filter' => ['channel' => ['is' => 'email']]])), 422, 'filter_unsupported');
        $this->assertProblem($this->get($this->base . '/overview?period=custom&from=2020-01-01&to=2020-02-01&' . http_build_query(['filter' => ['device' => ['is' => 'mobile']]])), 422, 'filter_unavailable_for_range');
    }

    public function testHourlyIntervalUsesRawEvents(): void
    {
        $this->ingest();
        $json = $this->json($this->get($this->base . '/timeseries?period=today&interval=hour'));
        self::assertSame('raw', $json['meta']['source']);
        $points = $json['data']['points'];
        self::assertCount(24, $points);
        $byHour = [];
        foreach ($points as $point) {
            $byHour[(string) $point['t']] = $point['pageviews'];
        }
        self::assertSame(1, $byHour['2026-09-17 11:00'], 'ingested at 09:00 UTC = 11:00 in Rome');
        self::assertSame(0, $byHour['2026-09-17 12:00']);
    }

    public function testRealtimeConsentAndCohorts(): void
    {
        $this->ingest();
        self::assertSame(0, $this->data($this->get($this->base . '/realtime'))['active_visitors'], 'the seeded events are older than five minutes');

        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/live')]), [], '203.0.113.55');
        self::assertSame(['/live'], $this->db->fetchFirstColumn('SELECT path FROM events_raw WHERE site_id = ? AND received_at >= ?', [$this->site->id(), '2026-09-17 09:55:00']));
        $this->clearCaches(); // the realtime report is cached for ten seconds
        $realtime = $this->data($this->get($this->base . '/realtime'));
        self::assertSame(1, $realtime['active_visitors']);
        self::assertCount(30, $realtime['pageviews_per_minute']);
        self::assertSame([['host' => 'www.site.test', 'path' => '/live', 'pageviews' => 1]], $realtime['top_pages']);

        $consent = $this->data($this->get($this->base . '/consent?period=7d'));
        self::assertSame([], $consent['rows']);
        self::assertNull($consent['totals']['acceptance_rate']);

        $cohorts = $this->data($this->get($this->base . '/cohorts?cohort=month&periods=3'));
        self::assertCount(3, $cohorts['rows']);
        self::assertSame(0, $cohorts['rows'][2]['size'], 'no consented visitors in this scenario');
    }

    public function testCsvExportAndCaching(): void
    {
        $this->ingest();
        $csv = $this->request('GET', $this->base . '/pages?period=7d', null, ['Accept' => 'text/csv']);
        $this->assertStatus(200, $csv);
        self::assertStringStartsWith('text/csv', $csv->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment; filename="pages-2026-09-11-2026-09-17.csv"', $csv->getHeaderLine('Content-Disposition'));
        $body = (string) $csv->getBody();
        self::assertStringContainsString('host,path,pageviews', $body);
        self::assertStringContainsString('www.site.test,/pricing,2', $body);

        $first = $this->get($this->base . '/overview?period=7d');
        self::assertSame('miss', $this->json($first)['meta']['cache']);
        $second = $this->get($this->base . '/overview?period=7d');
        self::assertSame('hit', $this->json($second)['meta']['cache']);
        self::assertSame('private, max-age=30', $second->getHeaderLine('Cache-Control'));

        $etag = $second->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        $notModified = $this->get($this->base . '/overview?period=7d', ['If-None-Match' => $etag]);
        $this->assertStatus(304, $notModified);
        self::assertSame('', (string) $notModified->getBody());
    }

    public function testPaginationWithCursor(): void
    {
        $this->ingest();
        $first = $this->json($this->get($this->base . '/pages?period=7d&limit=2'));
        self::assertCount(2, $first['data']['rows']);
        self::assertSame(3, $first['data']['total_rows']);
        $cursor = $first['meta']['next_cursor'];
        self::assertIsString($cursor);
        $second = $this->json($this->get($this->base . '/pages?period=7d&limit=2&cursor=' . $cursor));
        self::assertCount(1, $second['data']['rows']);
        self::assertNull($second['meta']['next_cursor']);
    }
}
