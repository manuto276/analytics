<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Tracking;

use Analytics\Shared\Crypto\Base64Url;
use Analytics\Sites\Domain\DntMode;
use Analytics\Sites\Domain\Site;
use Analytics\Sites\Domain\VisitorHashMode;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;

final class CollectTest extends HttpTestCase
{
    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->factory->site(['timezone' => 'Europe/Rome'], ['site.test']);
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM events_raw WHERE site_id = ? ORDER BY occurred_at, id', [$this->site->id()]);
    }

    /** @return list<array<string, mixed>> */
    private function visits(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM visits WHERE site_id = ? ORDER BY started_at, id', [$this->site->id()]);
    }

    public function testBasePageviewIsStoredAnonymously(): void
    {
        $response = $this->collect(Payloads::batch($this->site->publicKey, [
            Payloads::pageview('https://www.site.test/pricing?utm_source=newsletter&utm_medium=email&gclid=zzz', 'https://mail.google.com/'),
        ]));

        $this->assertStatus(202, $response);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('https://www.site.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('cross-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));

        $events = $this->events();
        self::assertCount(1, $events);
        $e = $events[0];
        self::assertSame('b', $e['level']);
        self::assertSame('pv', $e['type']);
        self::assertNotNull($e['visitor_hash']);
        self::assertNull($e['visitor_id']);
        self::assertSame('www.site.test', $e['host']);
        self::assertSame('/pricing', $e['path']);
        self::assertSame('utm_medium=email&utm_source=newsletter', $e['query']);
        self::assertSame('paid_search', $e['channel'], 'gclid wins');
        self::assertSame('Chrome', $e['browser']);
        self::assertSame('desktop', $e['device']);
        self::assertSame(1, (int) $e['is_entry']);
        self::assertSame('2026-09-17', $e['local_day']);

        $visits = $this->visits();
        self::assertCount(1, $visits);
        self::assertSame(1, (int) $visits[0]['pageviews']);
        self::assertSame(1, (int) $visits[0]['is_bounce']);
        self::assertSame((string) $e['visit_id'], (string) $visits[0]['id']);

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ? AND day = ?', [$this->site->id(), '2026-09-17']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM daily_salts'));
    }

    public function testJsonContentTypeAndRefererFallback(): void
    {
        $this->assertStatus(202, $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), ['Content-Type' => 'application/json', 'Origin' => '', 'Referer' => 'https://app.site.test/dashboard']));
        self::assertCount(1, $this->events());
    }

    public function testOriginIsEnforced(): void
    {
        $batch = Payloads::batch($this->site->publicKey, [Payloads::pageview()]);
        $this->assertProblem($this->collect($batch, ['Origin' => 'https://other.test']), 403, 'origin_not_allowed');
        $this->assertProblem($this->collect($batch, ['Origin' => 'https://evilsite.test']), 403, 'origin_not_allowed');
        $this->assertProblem($this->collect($batch, ['Origin' => '']), 403, 'origin_not_allowed');
        self::assertCount(0, $this->events());

        $subdomainOnly = $this->factory->site([], ['www.shop.test'], false);
        $this->assertProblem($this->collect(Payloads::batch($subdomainOnly->publicKey, [Payloads::pageview('https://app.shop.test/')]), ['Origin' => 'https://app.shop.test']), 403, 'origin_not_allowed');
    }

    public function testMalformedRequests(): void
    {
        $this->assertProblem($this->request('POST', '/t/e', '{not json', ['Origin' => 'https://www.site.test', 'Content-Type' => 'text/plain']), 400, 'invalid_json');
        $this->assertProblem($this->collect(['v' => 9, 'k' => $this->site->publicKey, 'l' => 'b', 'e' => [Payloads::pageview()]]), 400, 'unsupported_version');
        $this->assertProblem($this->collect(Payloads::batch('pk_' . str_repeat('Z', 21), [Payloads::pageview()])), 404, 'unknown_site');
        $big = Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/' . str_repeat('x', 2000))]);
        $big['padding'] = str_repeat('x', 70000);
        $this->assertProblem($this->collect($big), 413, 'payload_too_large');
    }

    public function testBotsAndClientsWithoutLanguageAreDroppedSilently(): void
    {
        $batch = Payloads::batch($this->site->publicKey, [Payloads::pageview()]);
        $this->assertStatus(202, $this->collect($batch, ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']));
        $this->assertStatus(202, $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), ['Accept-Language' => '']));
        $this->assertStatus(202, $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), ['User-Agent' => '']));
        self::assertCount(0, $this->events());
    }

    public function testRateLimitPerSiteAndAddress(): void
    {
        $batch = Payloads::batch($this->site->publicKey, [Payloads::consentStat('shown')]);
        for ($i = 0; $i < 300; ++$i) {
            $this->collect($batch);
        }
        $this->assertProblem($this->collect($batch), 429, 'rate_limited');
        $this->assertStatus(202, $this->collect($batch, [], '198.51.100.9'));
    }

    public function testBatchesAreIdempotent(): void
    {
        $batch = Payloads::batch($this->site->publicKey, [Payloads::pageview(), Payloads::event('signup')]);
        $this->collect($batch);
        $this->collect($batch);
        self::assertCount(2, $this->events(), 'the repeated batch is ignored');
        $visits = $this->visits();
        self::assertCount(1, $visits);
        self::assertSame(1, (int) $visits[0]['pageviews']);
        self::assertSame(1, (int) $visits[0]['events']);
        self::assertSame(0, (int) $visits[0]['is_bounce'], 'an interaction event is not a bounce');
    }

    public function testThirtyMinuteInactivityStartsANewVisit(): void
    {
        $key = $this->site->publicKey;
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/a')]));
        $this->clock->sleep(29 * 60);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/b', 'https://www.site.test/a')]));
        $this->clock->sleep(31 * 60);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/c', 'https://www.site.test/b')]));

        $visits = $this->visits();
        self::assertCount(2, $visits);
        self::assertSame(2, (int) $visits[0]['pageviews']);
        self::assertSame(0, (int) $visits[0]['is_bounce']);
        self::assertSame(1, (int) $visits[1]['pageviews']);
        self::assertSame('internal', $visits[1]['channel']);
        $events = $this->events();
        self::assertSame([1, 0, 1], array_map(static fn(array $e): int => (int) $e['is_entry'], $events));
        self::assertSame(bin2hex((string) $events[1]['page_hash']), bin2hex((string) $visits[0]['exit_page_hash']));
    }

    public function testLocalMidnightSplitsVisits(): void
    {
        $this->clock->modify('2026-09-17 21:50:00'); // 23:50 in Rome
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/a')]));
        $this->clock->modify('2026-09-17 22:05:00'); // 00:05 next day in Rome
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/b')]));

        $visits = $this->visits();
        self::assertCount(2, $visits);
        self::assertSame(['2026-09-17', '2026-09-18'], array_column($visits, 'local_day'));
    }

    public function testNewCampaignStartsNewVisitUnlessDisabled(): void
    {
        $key = $this->site->publicKey;
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/')]));
        $this->clock->sleep(60);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/?utm_source=partner&utm_campaign=x')]));
        self::assertCount(2, $this->visits());

        $this->site->newVisitOnCampaignChange = false;
        $this->em->flush();
        $this->container->get(\Symfony\Component\Cache\Adapter\AdapterInterface::class)->clear();
        $this->clock->sleep(60);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/?utm_source=other')]));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visits WHERE site_id = ?', [$this->site->id()]), 'with the flag off the campaign change continues the visit');
    }

    public function testVisitorHashDependsOnNetworkUserAgentAndDay(): void
    {
        $key = $this->site->publicKey;
        $this->collect(Payloads::batch($key, [Payloads::pageview()]), [], '203.0.113.10');
        $this->collect(Payloads::batch($key, [Payloads::pageview()]), [], '203.0.113.99');
        $this->collect(Payloads::batch($key, [Payloads::pageview()]), ['User-Agent' => Payloads::IPHONE_UA], '203.0.113.10');
        $this->clock->modify('2026-09-18 10:00:00');
        $this->collect(Payloads::batch($key, [Payloads::pageview()]), [], '203.0.113.10');

        $hashes = array_column($this->events(), 'visitor_hash');
        self::assertSame($hashes[0], $hashes[1]);
        self::assertNotSame($hashes[0], $hashes[2]);
        self::assertNotSame($hashes[0], $hashes[3], 'the salt rotates daily');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM daily_salts'), 'previous salt destroyed');
        self::assertCount(3, $this->visits());
    }

    public function testPageviewsOnlyModeStoresNoHashOrVisit(): void
    {
        $site = $this->factory->site(['visitorHashMode' => VisitorHashMode::PageviewsOnly], ['po.test']);
        $this->collect(Payloads::batch($site->publicKey, [
            Payloads::pageview('https://po.test/', 'https://www.google.com/'),
            Payloads::pageview('https://po.test/next', 'https://po.test/'),
        ]), ['Origin' => 'https://po.test']);
        $events = $this->db->fetchAllAssociative('SELECT visitor_hash, visit_id, is_entry FROM events_raw WHERE site_id = ? ORDER BY id', [$site->id()]);
        self::assertSame([[null, null, 1], [null, null, 0]], array_map(static fn(array $r): array => [$r['visitor_hash'], $r['visit_id'], (int) $r['is_entry']], $events));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visits WHERE site_id = ?', [$site->id()]));
    }

    public function testEventsEngagementAndProps(): void
    {
        $this->collect(Payloads::batch($this->site->publicKey, [
            Payloads::pageview('https://www.site.test/pricing'),
            Payloads::event('signup_click', ['plan' => 'pro', 'email' => 'x@example.com', 'n' => 3]),
            Payloads::engagement(12000, 75, 'https://www.site.test/pricing'),
        ]));
        $events = $this->events();
        self::assertSame(['pv', 'ev', 'en'], array_column($events, 'type'));
        self::assertSame('signup_click', $events[1]['name']);
        self::assertSame(['n' => 3, 'plan' => 'pro', 'email' => '[email]'], json_decode((string) $events[1]['props'], true));
        self::assertSame(12000, (int) $events[2]['engagement_ms']);
        self::assertSame(75, (int) $events[2]['scroll_pct']);
        $visit = $this->visits()[0];
        self::assertSame(12000, (int) $visit['engagement_ms']);
        self::assertSame(1, (int) $visit['events']);
    }

    public function testEventAgeMovesOccurredAt(): void
    {
        $this->collect(Payloads::batch($this->site->publicKey, [['a' => 90_000] + Payloads::pageview()]));
        $e = $this->events()[0];
        self::assertSame('2026-09-17 09:58:30.000', $e['occurred_at']);
        self::assertSame('2026-09-17 10:00:00.000', $e['received_at']);
    }

    public function testForeignHostsAndExclusionsAreDropped(): void
    {
        $this->site->excludedPaths = ['/admin'];
        $this->site->excludedIpPrefixes = ['192.0.2.0/24'];
        $this->em->flush();
        $this->collect(Payloads::batch($this->site->publicKey, [
            Payloads::pageview('https://www.other.test/'),
            Payloads::pageview('https://www.site.test/admin/users'),
            Payloads::pageview('https://www.site.test/ok'),
        ]));
        self::assertSame(['/ok'], array_column($this->events(), 'path'));

        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), [], '192.0.2.44');
        self::assertCount(1, $this->events());
    }

    public function testDoNotTrackModes(): void
    {
        $this->site->dntMode = DntMode::NoTracking;
        $this->em->flush();
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), ['DNT' => '1']);
        self::assertCount(0, $this->events());
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()]), ['DNT' => '0']);
        self::assertCount(1, $this->events());
    }

    public function testConsentedFlowUpgradesVisitAndRecordsTouches(): void
    {
        $this->site->cookieLevelEnabled = true;
        $this->em->flush();
        $key = $this->site->publicKey;
        $vid = Payloads::id22();
        $sid = Payloads::id22();

        // Landing without consent, then the visitor accepts on the same page.
        $landing = 'https://www.site.test/?utm_source=google&utm_medium=cpc&utm_campaign=brand';
        $this->collect(Payloads::batch($key, [Payloads::pageview($landing, 'https://www.google.com/'), Payloads::consentStat('shown')]));
        $this->clock->sleep(20);
        $this->collect(Payloads::batch($key, [Payloads::consentStat('accept')]));
        $this->collect(Payloads::batch($key, [Payloads::consentUpgrade($landing, 'https://www.google.com/')], 'c', ['vid' => $vid, 'sid' => $sid, 'cv' => 1]));
        $this->clock->sleep(60);
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/pricing', $landing)], 'c', ['vid' => $vid, 'sid' => $sid, 'cv' => 1]));

        $visits = $this->visits();
        self::assertCount(1, $visits, 'the base visit is upgraded, not duplicated');
        self::assertSame('c', $visits[0]['level']);
        self::assertSame(Base64Url::decode($vid), $visits[0]['visitor_id']);
        self::assertSame(2, (int) $visits[0]['pageviews']);
        self::assertSame('paid_search', $visits[0]['channel']);

        $events = $this->events();
        self::assertSame(['b', 'c', 'c'], array_column($events, 'level'));
        self::assertNull($events[0]['visitor_id'], 'base rows keep no visitor id');
        self::assertSame(Base64Url::decode($vid), $events[2]['visitor_id']);

        $visitor = $this->db->fetchAssociative('SELECT * FROM visitors WHERE site_id = ?', [$this->site->id()]);
        self::assertIsArray($visitor);
        self::assertSame(1, (int) $visitor['consent_version']);
        $touches = $this->db->fetchAllAssociative('SELECT * FROM attribution_touches WHERE site_id = ?', [$this->site->id()]);
        self::assertCount(1, $touches);
        self::assertSame(1, (int) $touches[0]['is_first']);
        self::assertSame('brand', $touches[0]['utm_campaign']);
        self::assertSame((string) $touches[0]['id'], (string) $visitor['first_touch_id']);

        $stats = $this->db->fetchAssociative('SELECT shown, accepted FROM consent_stats_daily WHERE site_id = ?', [$this->site->id()]);
        self::assertSame(['shown' => 1, 'accepted' => 1], array_map('intval', (array) $stats));

        // Two days later the visitor returns from a newsletter: new visit and a non-first touch.
        $this->clock->sleep(2 * 86400);
        $sid2 = Payloads::id22();
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/?utm_source=newsletter&utm_medium=email')], 'c', ['vid' => $vid, 'sid' => $sid2, 'cv' => 1]));
        $this->collect(Payloads::batch($key, [Payloads::pageview('https://www.site.test/x', 'https://www.site.test/')], 'c', ['vid' => $vid, 'sid' => $sid2, 'cv' => 1]));
        self::assertCount(2, $this->visits());
        $touches = $this->db->fetchAllAssociative('SELECT is_first, channel FROM attribution_touches WHERE site_id = ? ORDER BY id', [$this->site->id()]);
        self::assertSame([['is_first' => 1, 'channel' => 'paid_search'], ['is_first' => 0, 'channel' => 'email']], array_map(static fn(array $t): array => ['is_first' => (int) $t['is_first'], 'channel' => $t['channel']], $touches));
        self::assertSame(2, (int) $this->db->fetchOne('SELECT visits FROM visitors WHERE site_id = ?', [$this->site->id()]));
    }

    public function testConsentedBatchIsDowngradedWhenCookieLevelIsOffOrGpc(): void
    {
        $batch = Payloads::batch($this->site->publicKey, [Payloads::pageview()], 'c', ['vid' => Payloads::id22(), 'sid' => Payloads::id22()]);
        $this->collect($batch);
        self::assertNull($this->events()[0]['visitor_id']);

        $this->site->cookieLevelEnabled = true;
        $this->em->flush();
        $this->container->get(\Symfony\Component\Cache\Adapter\AdapterInterface::class)->clear();
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()], 'c', ['vid' => Payloads::id22(), 'sid' => Payloads::id22()]), ['Sec-GPC' => '1']);
        self::assertSame(['b', 'b'], array_column($this->events(), 'level'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visitors'));
    }

    public function testForgetErasesCookieLevelDataOnly(): void
    {
        $this->site->cookieLevelEnabled = true;
        $this->em->flush();
        $vid = Payloads::id22();
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/base')]), [], '198.51.100.1');
        $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview('https://www.site.test/?utm_source=x')], 'c', ['vid' => $vid, 'sid' => Payloads::id22()]), [], '198.51.100.2');
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visitors'));

        $response = $this->request('POST', '/t/forget', json_encode(['k' => $this->site->publicKey, 'vid' => $vid], \JSON_THROW_ON_ERROR), ['Origin' => 'https://www.site.test', 'Content-Type' => 'text/plain']);
        $this->assertStatus(202, $response);
        self::assertSame(['/base'], array_column($this->events(), 'path'));
        self::assertCount(1, $this->visits());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visitors'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM attribution_touches'));

        $this->assertProblem($this->request('POST', '/t/forget', json_encode(['k' => $this->site->publicKey, 'vid' => 'bad'], \JSON_THROW_ON_ERROR), ['Origin' => 'https://www.site.test', 'Content-Type' => 'text/plain']), 400);
        $this->assertProblem($this->request('POST', '/t/forget', json_encode(['k' => $this->site->publicKey, 'vid' => $vid], \JSON_THROW_ON_ERROR), ['Origin' => 'https://other.test', 'Content-Type' => 'text/plain']), 403);
    }

    public function testTrackingEndpointsNeverSetOrReadCookies(): void
    {
        $this->cookies = ['__Host-an_session' => 'x', 'an_vid' => 'y'];
        foreach ([
            $this->collect(Payloads::batch($this->site->publicKey, [Payloads::pageview()])),
            $this->request('OPTIONS', '/t/e', null, ['Origin' => 'https://www.site.test']),
            $this->request('GET', '/t/' . $this->site->publicKey . '.js'),
            $this->request('GET', '/t/pk_' . str_repeat('Q', 21) . '.js'),
            $this->request('POST', '/t/forget', '{}', ['Origin' => 'https://www.site.test']),
            $this->request('POST', '/t/e', 'garbage', ['Origin' => 'https://www.site.test']),
        ] as $response) {
            self::assertFalse($response->hasHeader('Set-Cookie'));
        }
    }
}
