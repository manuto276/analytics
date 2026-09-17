<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ConversionTest extends TestCase
{
    private const VID = 'AbCdEfGhIjKlMnOpQr_-12';

    /** @var array{url: string, args: array<string, mixed>}|null */
    private ?array $request = null;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_generate_uuid4')->justReturn('0b7a1f2e-3c4d-4e5f-8a9b-0c1d2e3f4a5b');
        Functions\when('wp_remote_post')->alias(function (string $url, array $args): array {
            $this->request = ['url' => $url, 'args' => $args];

            return ['response' => ['code' => 200]];
        });
    }

    public function testPostsFullPayloadToConversionsEndpoint(): void
    {
        $this->configure();
        $_COOKIE['an_vid'] = self::VID;

        $result = analytics_connector_track_conversion('purchase', [
            'id' => 'order-8812',
            'occurred_at' => new \DateTimeImmutable('2026-09-17 12:00:00', new \DateTimeZone('Europe/Rome')),
            'customer_ref' => 'opaque-123',
            'value' => ['amount_minor' => '4900', 'currency' => 'eur'],
            'props' => ['plan' => 'pro'],
        ]);

        self::assertTrue($result);
        self::assertNotNull($this->request);
        self::assertSame('https://stats.example.net/api/v1/server/sites/' . self::KEY . '/conversions', $this->request['url']);

        $args = $this->request['args'];
        self::assertFalse($args['blocking']);
        self::assertSame(2, $args['timeout']);
        self::assertSame(['Authorization' => 'Bearer ak_test_secret', 'Content-Type' => 'application/json'], $args['headers']);
        self::assertSame([
            'id' => 'order-8812',
            'name' => 'purchase',
            'occurred_at' => '2026-09-17T10:00:00Z',
            'visitor_id' => self::VID,
            'customer_ref' => 'opaque-123',
            'value' => ['amount_minor' => 4900, 'currency' => 'EUR'],
            'props' => ['plan' => 'pro'],
        ], json_decode($args['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testMinimalPayloadGeneratesIdAndCurrentTime(): void
    {
        $this->configure();

        $before = time();
        self::assertTrue(analytics_connector_track_conversion('signup'));
        $payload = json_decode($this->request['args']['body'] ?? '', true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['id', 'name', 'occurred_at'], array_keys($payload));
        self::assertSame('0b7a1f2e-3c4d-4e5f-8a9b-0c1d2e3f4a5b', $payload['id']);
        self::assertSame('signup', $payload['name']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['occurred_at']);
        self::assertGreaterThanOrEqual($before, strtotime($payload['occurred_at']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCookies(): iterable
    {
        yield 'too short' => ['abc'];
        yield 'too long' => [self::VID . 'x'];
        yield 'bad characters' => ['AbCdEfGhIjKlMnOpQr+/12'];
        yield 'injection' => ['"}],"x":"AbCdEfGhIjKlMn'];
        yield 'trailing newline' => [self::VID . "\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCookies')]
    public function testInvalidVisitorCookieIsIgnored(string $cookie): void
    {
        $this->configure();
        $_COOKIE['an_vid'] = $cookie;

        self::assertTrue(analytics_connector_track_conversion('purchase', ['id' => 'o-1']));
        self::assertArrayNotHasKey('visitor_id', json_decode($this->request['args']['body'] ?? '', true, flags: JSON_THROW_ON_ERROR));
    }

    public function testExplicitVisitorIdOverridesCookie(): void
    {
        $this->configure();
        $_COOKIE['an_vid'] = self::VID;

        analytics_connector_track_conversion('purchase', ['visitor_id' => 'ZZZZZZZZZZZZZZZZZZZZZZ']);

        self::assertSame('ZZZZZZZZZZZZZZZZZZZZZZ', json_decode($this->request['args']['body'] ?? '', true)['visitor_id']);
    }

    public function testReturnsFalseWhenNotConfigured(): void
    {
        Functions\expect('wp_remote_post')->never();

        self::assertFalse(analytics_connector_track_conversion('purchase'));
    }

    public function testReturnsFalseOnTransportError(): void
    {
        $this->configure();
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_request_failed', 'nope'));

        self::assertFalse(analytics_connector_track_conversion('purchase'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsFalseWithoutApiKeyConstant(): void
    {
        // Fresh process, so ANALYTICS_CONNECTOR_API_KEY is not defined.
        parent::configure();
        Functions\expect('wp_remote_post')->never();

        self::assertFalse(\defined('ANALYTICS_CONNECTOR_API_KEY'));
        self::assertFalse(analytics_connector_track_conversion('purchase', ['id' => 'o-1']));
    }

    /**
     * Configures the plugin and defines the API key constant.
     *
     * @param array<string, string> $settings
     */
    protected function configure(array $settings = []): void
    {
        parent::configure($settings);
        if (!\defined('ANALYTICS_CONNECTOR_API_KEY')) {
            \define('ANALYTICS_CONNECTOR_API_KEY', 'ak_test_secret');
        }
    }
}
