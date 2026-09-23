<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;

/** WordPress → the service: which reports and parameters go out, how, and what comes back. */
final class ClientTest extends TestCase
{
    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, mixed>|\WP_Error */
    private array|\WP_Error $response = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->response = self::answer(200, ['data' => ['metrics' => ['visitors' => 3]], 'meta' => ['range' => ['from' => '2026-09-11', 'to' => '2026-09-17']]]);
        Functions\when('wp_remote_get')->alias(function (string $url, array $args): array|\WP_Error {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return $this->response;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);
    }

    /** @param array<string, mixed> $body */
    private static function answer(int $status, array $body = []): array
    {
        return ['response' => ['code' => $status], 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
    }

    protected function configure(array $settings = []): void
    {
        parent::configure($settings);
        if (!\defined('ANALYTICS_CONNECTOR_API_KEY')) {
            \define('ANALYTICS_CONNECTOR_API_KEY', 'ak_test_secret');
        }
    }

    public function testOnlyKnownReportsAndAllowedValuesGoOut(): void
    {
        self::assertNull(analytics_connector_clean_report_params('cohorts', []));
        self::assertNull(analytics_connector_clean_report_params('../sites', []));
        self::assertSame(
            ['compare' => 'previous_period', 'period' => '7d'],
            analytics_connector_clean_report_params('overview', ['period' => '7d', 'compare' => 'previous_period', 'limit' => '5', 'site' => '2']),
        );
        self::assertSame(
            ['from' => '2026-09-01', 'period' => 'custom', 'to' => '2026-09-17'],
            analytics_connector_clean_report_params('overview', ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-17']),
        );
        self::assertSame([], analytics_connector_clean_report_params('pages', ['period' => 'forever', 'from' => '1 Sept', 'limit' => '1000', 'kind' => ['top']]));
        self::assertSame(['group' => 'device', 'limit' => '8'], analytics_connector_clean_report_params('tech', ['group' => 'device', 'limit' => '8']));
        self::assertSame([], analytics_connector_clean_report_params('realtime', ['period' => '7d']));
    }

    public function testTheReportIsReadWithTheKeyAndCached(): void
    {
        $this->configure();

        $first = analytics_connector_report('overview', ['period' => '7d']);
        $second = analytics_connector_report('overview', ['period' => '7d']);

        self::assertSame(3, $first['data']['metrics']['visitors']);
        self::assertSame($first, $second);
        self::assertCount(1, $this->requests, 'the second call is served from the cache');
        self::assertSame('https://stats.example.net/api/v1/server/sites/' . self::KEY . '/reports/overview?period=7d', $this->requests[0]['url']);
        self::assertSame('Bearer ak_test_secret', $this->requests[0]['args']['headers']['Authorization']);
        self::assertSame(0, $this->requests[0]['args']['redirection'], 'the key is never replayed to another host');
    }

    public function testNothingIsAskedWithoutASetUp(): void
    {
        $this->options['analytics_connector_settings'] = ['service_url' => '', 'public_key' => ''];

        $error = analytics_connector_report('overview');

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('analytics_not_connected', $error->get_error_code());
        self::assertSame([], $this->requests);
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function refusals(): iterable
    {
        yield 'revoked key' => [401, 'analytics_key_invalid', 409];
        yield 'key without reports:read' => [403, 'analytics_key_scope', 409];
        yield 'another site, or an old service' => [404, 'analytics_not_found', 409];
        yield 'bad period' => [422, 'analytics_bad_request', 422];
        yield 'rate limited' => [429, 'analytics_rate_limited', 429];
        yield 'service down' => [503, 'analytics_service_error', 502];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testTheServiceRefusalsBecomeSentences(int $status, string $code, int $wpStatus): void
    {
        $this->configure();
        $this->response = self::answer($status, ['title' => '<b>internal detail</b>']);

        $error = analytics_connector_report('overview');
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame($code, $error->get_error_code());
        self::assertSame(['status' => $wpStatus], $error->get_error_data());
        self::assertStringNotContainsString('internal detail', $error->get_error_message());

        // Remembered for a while: a service that is down does not cost every page the timeout.
        analytics_connector_report('overview');
        self::assertCount(1, $this->requests);
    }

    public function testAFlushMeansAskingAgain(): void
    {
        $this->configure();
        analytics_connector_report('pages');
        analytics_connector_flush_reports();
        analytics_connector_report('pages');

        self::assertCount(2, $this->requests);
    }

    public function testAnUnreachableServiceOrAnUnreadableAnswer(): void
    {
        $this->configure();
        $this->response = new \WP_Error('http_request_failed', 'cURL error 28');
        self::assertSame('analytics_unreachable', analytics_connector_report('pages')->get_error_code());

        $this->response = ['response' => ['code' => 200], 'body' => '<html>'];
        self::assertSame('analytics_bad_response', analytics_connector_report('sources')->get_error_code());
    }

    public function testRealtimeIsCachedBriefly(): void
    {
        $this->configure();
        $ttl = [];
        Functions\when('set_transient')->alias(static function (string $name, mixed $value, int $expiration) use (&$ttl): bool {
            $ttl[] = $expiration;

            return true;
        });

        analytics_connector_report('realtime');
        analytics_connector_report('overview');

        self::assertSame([10, 60], $ttl);
    }
}
