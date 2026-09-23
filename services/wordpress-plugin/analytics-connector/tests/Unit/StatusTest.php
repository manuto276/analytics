<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;

/** What the Settings screen learns from the tracker script, without a key. */
final class StatusTest extends TestCase
{
    private const SCRIPT = "/*! Analytics tracker — source: https://stats.example.net/t/source */\nwindow.__an_cfg={\"k\":\"pk_AbCdEfGhIjKlMnOpQrStU\",\"c\":true,\"auto\":{\"outbound\":true,\"downloads\":false,\"forms\":true},\"consent\":{\"v\":3,\"dl\":\"it\",\"at\":180,\"rt\":180,\"texts\":{\"it\":{},\"en\":{}}}};\n\"use strict\";(()=>{})();";

    public function testTheConfigurationIsReadFromTheScript(): void
    {
        $cfg = analytics_connector_parse_tracker_config(self::SCRIPT);

        self::assertSame(self::KEY, $cfg['k']);
        self::assertNull(analytics_connector_parse_tracker_config('console.log(1)'));
        self::assertNull(analytics_connector_parse_tracker_config("window.__an_cfg={nope};\n"));
    }

    public function testTheTrackerCheck(): void
    {
        $this->configure();
        $asked = null;
        Functions\when('wp_remote_get')->alias(static function (string $url) use (&$asked): array {
            $asked = $url;

            return ['response' => ['code' => 200], 'body' => self::SCRIPT];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn (array $r): int => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);

        $status = analytics_connector_tracker_status();

        self::assertSame('https://stats.example.net/t/' . self::KEY . '.js', $asked);
        self::assertSame('ok', $status['state']);
        self::assertTrue($status['cookies']);
        self::assertTrue($status['banner']);
        self::assertSame(['it', 'en'], $status['languages']);
        self::assertSame(3, $status['version']);
        self::assertSame(['outbound' => true, 'downloads' => false, 'forms' => true], $status['auto']);
    }

    public function testATrackerNotServed(): void
    {
        $this->configure();
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 404], 'body' => 'Not found']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);
        Functions\when('wp_remote_retrieve_body')->justReturn('Not found');

        self::assertSame(['state' => 'error', 'url' => 'https://stats.example.net/t/' . self::KEY . '.js', 'status' => 404], analytics_connector_tracker_status());

        $this->options['analytics_connector_settings'] = [];
        self::assertSame(['state' => 'not_configured'], analytics_connector_tracker_status());
    }
}
