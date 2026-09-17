<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Tracking;

use Analytics\Consent\Application\ConsentService;
use Analytics\Kernel\Settings;
use Analytics\Shared\Net\IpTruncator;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\HttpTestCase;
use Analytics\Tests\Support\Payloads;
use Analytics\Tracking\Application\GeoLocator;
use Analytics\Tracking\Application\ScriptBundleBuilder;
use Analytics\Tracking\Infrastructure\DbalDailySaltProvider;
use Analytics\Tracking\Infrastructure\MaxMindGeoLocator;
use Psr\Cache\CacheItemPoolInterface;

final class TrackingEdgeCasesTest extends HttpTestCase
{
    public function testBaseTrackingCanBeTurnedOffWhileTheBannerStillCounts(): void
    {
        $site = $this->factory->site(['baseTrackingEnabled' => false, 'cookieLevelEnabled' => true], ['www.site.test']);
        $this->collect(Payloads::batch($site->publicKey, [
            Payloads::pageview('https://www.site.test/'),
            Payloads::event('signup_click'),
            Payloads::consentStat('shown'),
            Payloads::consentStat('dismiss'),
        ]));

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE site_id = ?', [$site->id()]));
        $stats = $this->db->fetchAssociative('SELECT shown, dismissed, rejected FROM consent_stats_daily WHERE site_id = ?', [$site->id()]);
        self::assertSame(['shown' => 1, 'dismissed' => 1, 'rejected' => 0], array_map('intval', (array) $stats));

        // Consented traffic is still collected.
        $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/pricing')], 'c', ['vid' => Payloads::id22(), 'sid' => Payloads::id22(), 'cv' => 1]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE site_id = ?', [$site->id()]));
    }

    public function testConsentUpgradeWithoutTheConsentedLevelIsDropped(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true], ['www.site.test']);
        $this->collect(Payloads::batch($site->publicKey, [Payloads::consentUpgrade('https://www.site.test/')]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE site_id = ?', [$site->id()]));
    }

    public function testLocalhostIsOnlyAcceptedWhenAllowed(): void
    {
        $site = $this->factory->site([], ['www.site.test']);
        $batch = Payloads::batch($site->publicKey, [Payloads::pageview('http://localhost:3000/dev')]);
        $this->assertProblem($this->collect($batch, ['Origin' => 'http://localhost:3000']), 403, 'origin_not_allowed');

        $site->allowLocalhost = true;
        $this->em->flush();
        $this->clearCaches();
        $this->assertStatus(202, $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview('http://localhost:3000/dev')]), ['Origin' => 'http://localhost:3000']));
        self::assertSame(['localhost'], $this->db->fetchFirstColumn('SELECT host FROM events_raw WHERE site_id = ?', [$site->id()]));
    }

    public function testScriptFallsBackToAStubWhenTheTrackerIsNotBuilt(): void
    {
        $site = SiteSnapshot::fromSite($this->factory->site([], ['www.site.test']));
        $consent = $this->service(ConsentService::class);
        $cache = $this->service(Settings::class);
        $pool = $this->container->get(CacheItemPoolInterface::class);
        \assert($pool instanceof CacheItemPoolInterface);

        $builder = new ScriptBundleBuilder($consent, $pool, '/does/not/exist/tracker.js', 'https://example.org/source');
        $bundle = $builder->build($site);
        self::assertStringContainsString('source: https://example.org/source', $bundle['body']);
        self::assertStringContainsString('window.__an_cfg=', $bundle['body']);
        self::assertStringContainsString('q:[]', $bundle['body'], 'the stub keeps the queue working');
        self::assertSame($bundle['body'], $builder->build($site)['body'], 'bundles are cached');
        self::assertNotSame('', $cache->sourceUrl);
    }

    public function testGeoLocatorIgnoresMissingAndBrokenDatabases(): void
    {
        $prefix = IpTruncator::truncate('81.2.69.142');
        self::assertNotNull($prefix);

        $missing = new MaxMindGeoLocator('/does/not/exist.mmdb');
        self::assertNull($missing->country($prefix));
        self::assertNull($missing->country(null));

        $broken = sys_get_temp_dir() . '/analytics-broken-' . bin2hex(random_bytes(4)) . '.mmdb';
        file_put_contents($broken, 'not a database');
        $locator = new MaxMindGeoLocator($broken);
        self::assertNull($locator->country($prefix));
        self::assertNull($locator->country($prefix), 'a broken file is not retried');
        unlink($broken);

        $real = new MaxMindGeoLocator(\dirname(__DIR__, 2) . '/fixtures/GeoIP2-Country-Test.mmdb');
        $italian = IpTruncator::truncate('2a02:ff00:1:2::9');
        self::assertNotNull($italian);
        self::assertSame('IT', $real->country($italian));
        self::assertSame('IT', $real->country($italian), 'lookups are memoised');
        self::assertNull($real->country(IpTruncator::truncate('10.0.0.1')));
    }

    public function testGeoLocatorIsWiredIntoIngestion(): void
    {
        $settings = $this->service(Settings::class);
        $dir = \dirname($settings->geoDbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        copy(\dirname(__DIR__, 2) . '/fixtures/GeoIP2-Country-Test.mmdb', $settings->geoDbPath);
        try {
            $site = $this->factory->site([], ['www.site.test']);
            $this->collect(Payloads::batch($site->publicKey, [Payloads::pageview()]), [], '2a02:ff00:1:2:3:4:5:6');
            self::assertSame(['IT'], $this->db->fetchFirstColumn('SELECT country FROM events_raw WHERE site_id = ?', [$site->id()]));
        } finally {
            @unlink($settings->geoDbPath);
        }
    }

    public function testDailySaltRotationRemovesOlderSalts(): void
    {
        $provider = new DbalDailySaltProvider($this->db);
        $today = $this->clock->now();
        $salt = $provider->saltFor($today);
        self::assertSame(32, \strlen($salt));
        self::assertSame($salt, $provider->saltFor($today), 'memoised for the day');

        $this->db->insert('daily_salts', ['day' => $today->modify('-1 day')->format('Y-m-d'), 'salt' => random_bytes(32), 'created_at' => $today->format('Y-m-d H:i:s.v')], ['salt' => \Doctrine\DBAL\ParameterType::BINARY]);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM daily_salts'));

        $provider->rotate($today);
        self::assertSame([$today->format('Y-m-d')], $this->db->fetchFirstColumn('SELECT day FROM daily_salts'));

        $tomorrow = $today->modify('+1 day');
        self::assertNotSame($salt, $provider->saltFor($tomorrow), 'a new day gets a new salt');
        self::assertSame([$tomorrow->format('Y-m-d')], $this->db->fetchFirstColumn('SELECT day FROM daily_salts'), 'yesterday is destroyed');
    }

    public function testPreflightAndUnknownSiteResponses(): void
    {
        $preflight = $this->request('OPTIONS', '/t/e', null, ['Origin' => 'https://www.site.test']);
        $this->assertStatus(204, $preflight);
        self::assertSame('POST, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('https://www.site.test', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('86400', $preflight->getHeaderLine('Access-Control-Max-Age'));

        $withoutOrigin = $this->request('OPTIONS', '/t/forget', null, ['Origin' => '']);
        $this->assertStatus(204, $withoutOrigin);
        self::assertSame('', $withoutOrigin->getHeaderLine('Access-Control-Allow-Origin'), 'no Origin, no CORS header');

        $this->assertProblem($this->request('POST', '/t/forget', ['k' => 'pk_' . str_repeat('Z', 21), 'vid' => Payloads::id22()], ['Origin' => 'https://www.site.test']), 404, 'unknown_site');
    }

    public function testGeoLocatorServiceIsTheMaxMindImplementation(): void
    {
        self::assertInstanceOf(MaxMindGeoLocator::class, $this->service(GeoLocator::class));
    }
}
