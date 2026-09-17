<?php

declare(strict_types=1);

namespace Analytics\Tests\Integration\Tracking;

use Analytics\Kernel\AppFactory;
use Analytics\Kernel\ConsoleApplicationFactory;
use Analytics\Shared\Net\IpTruncator;
use Analytics\Sites\Domain\SiteSnapshot;
use Analytics\Tests\Support\IntegrationTestCase;
use Analytics\Tests\Support\Payloads;
use Analytics\Tests\Support\TestContainer;
use Analytics\Tracking\Application\CollectContext;
use Analytics\Tracking\Application\CollectService;
use Analytics\Tracking\Application\DailySaltProvider;
use Analytics\Tracking\Infrastructure\RedisDailySaltProvider;
use Analytics\Tracking\Infrastructure\RedisQueueEventSink;
use DI\Container;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * INGEST_MODE=queue: the request only enqueues anonymised drafts; queue:work writes them.
 */
final class QueueModeTest extends IntegrationTestCase
{
    protected bool $useTransaction = false;

    private ?Container $queueContainer = null;

    private static function redisDsn(): string
    {
        $dsn = getenv('TEST_REDIS_DSN');

        return \is_string($dsn) && $dsn !== '' ? $dsn : 'redis://127.0.0.1:63791';
    }

    protected function setUp(): void
    {
        parent::setUp();
        try {
            new \Predis\Client(self::redisDsn())->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not available: ' . $e->getMessage());
        }
        $this->queueContainer = TestContainer::build(['INGEST_MODE' => 'queue', 'REDIS_DSN' => self::redisDsn()]);
        $redis = $this->queueContainer->get('redis');
        \assert($redis instanceof \Predis\ClientInterface);
        $redis->del([RedisQueueEventSink::KEY]);
    }

    protected function tearDown(): void
    {
        if ($this->queueContainer !== null) {
            $connection = $this->queueContainer->get(Connection::class);
            \assert($connection instanceof Connection);
            $connection->close();
            $this->queueContainer = null;
        }
        parent::tearDown();
    }

    public function testEventsAreQueuedThenWrittenByTheWorker(): void
    {
        $container = $this->queueContainer;
        self::assertNotNull($container);
        $site = $this->factory->site([], ['www.site.test']);
        $snapshot = SiteSnapshot::fromSite($site);

        $collect = $container->get(CollectService::class);
        \assert($collect instanceof CollectService);
        $payload = Payloads::batch($site->publicKey, [
            Payloads::pageview('https://www.site.test/queued'),
            Payloads::event('signup_click', ['plan' => 'pro'], 'https://www.site.test/queued'),
        ]);
        $context = new CollectContext(
            ipPrefix: IpTruncator::truncate('203.0.113.7'),
            userAgent: Payloads::CHROME_UA,
            acceptLanguage: 'en',
            origin: 'https://www.site.test',
            referer: '',
            doNotTrack: false,
            globalPrivacyControl: false,
        );
        $result = $collect->collect($payload, $context);
        self::assertSame(2, $result['accepted']);

        $queue = $container->get(RedisQueueEventSink::class);
        \assert($queue instanceof RedisQueueEventSink);
        self::assertSame(2, $queue->length(), 'the request only enqueues');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM events_raw WHERE site_id = ?', [$site->id()]));

        $application = ConsoleApplicationFactory::create($container);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'queue:work', '--once' => true, '--max-time' => '5']), $output));

        self::assertSame(0, $queue->length());
        $rows = $this->db->fetchAllAssociative('SELECT type, path, visitor_hash, props FROM events_raw WHERE site_id = ? ORDER BY id', [$site->id()]);
        self::assertSame(['pv', 'ev'], array_column($rows, 'type'));
        self::assertSame('/queued', $rows[0]['path']);
        self::assertNotNull($rows[0]['visitor_hash']);
        self::assertSame(['plan' => 'pro'], json_decode((string) $rows[1]['props'], true));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM visits WHERE site_id = ?', [$site->id()]));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM rollup_dirty WHERE site_id = ?', [$site->id()]));
    }

    public function testQueuedPayloadsCarryNoIpOrUserAgent(): void
    {
        $container = $this->queueContainer;
        self::assertNotNull($container);
        $site = $this->factory->site([], ['www.site.test']);
        $collect = $container->get(CollectService::class);
        \assert($collect instanceof CollectService);
        $collect->collect(
            Payloads::batch($site->publicKey, [Payloads::pageview('https://www.site.test/x')]),
            new CollectContext(IpTruncator::truncate('198.51.100.77'), Payloads::CHROME_UA, 'en', 'https://www.site.test', '', false, false),
        );

        $redis = $container->get('redis');
        \assert($redis instanceof \Predis\ClientInterface);
        $raw = (string) $redis->lindex(RedisQueueEventSink::KEY, 0);
        self::assertStringNotContainsString('198.51.100.77', $raw);
        self::assertStringNotContainsString('Chrome/126', $raw);
        self::assertStringContainsString('"dev":"desktop"', $raw);
    }

    public function testSaltLivesInRedisWhenConfigured(): void
    {
        $container = $this->queueContainer;
        self::assertNotNull($container);
        $salts = $container->get(DailySaltProvider::class);
        self::assertInstanceOf(RedisDailySaltProvider::class, $salts);
        $salt = $salts->saltFor($this->clock->now());
        self::assertSame(32, \strlen($salt));
        self::assertSame($salt, $salts->saltFor($this->clock->now()));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM daily_salts'), 'no salt is stored in MySQL');
    }

    public function testTheAppStillBootsInQueueMode(): void
    {
        $container = $this->queueContainer;
        self::assertNotNull($container);
        $app = AppFactory::create($container);
        self::assertNotSame([], $app->getRouteCollector()->getRoutes());
    }
}
