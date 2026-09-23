<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;

/** The admin app's API: validation before saving, and a key that goes in and never comes out. */
final class RestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('rest_ensure_response')->returnArg();
    }

    public function testAnInvalidPublicKeyIsRefusedBeforeAnythingIsSaved(): void
    {
        $this->configure();
        Functions\expect('update_option')->never();

        $error = analytics_connector_rest_save_settings(new \WP_REST_Request([], [], ['public_key' => 'pk_nope']));

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame(['status' => 400], $error->get_error_data());
    }

    public function testAnInvalidApiKeyIsRefused(): void
    {
        $this->configure();
        Functions\expect('update_option')->never();

        $error = analytics_connector_rest_save_settings(new \WP_REST_Request([], [], ['public_key' => self::KEY, 'api_key' => 'my password']));

        self::assertSame('analytics_invalid_api_key', $error->get_error_code());
    }

    public function testTheSettingsAreSavedSanitisedAndTheKeyIsNotInTheAnswer(): void
    {
        $this->configure();
        if (!\defined('ANALYTICS_CONNECTOR_API_KEY')) {
            \define('ANALYTICS_CONNECTOR_API_KEY', 'ak_test_secret');
        }

        $answer = analytics_connector_rest_save_settings(new \WP_REST_Request([], [], [
            'service_url' => 'https://stats.example.net/',
            'public_key' => self::KEY,
            'mode' => 'direct',
            'skip_capability' => 'manage_options',
            'global_name' => 'analytics',
            'surprise' => 'dropped',
        ]));

        self::assertSame('https://stats.example.net', $this->options['analytics_connector_settings']['service_url']);
        self::assertArrayNotHasKey('surprise', $this->options['analytics_connector_settings']);
        self::assertSame('ak_test_secr…', $answer['api_key']['hint']);
        self::assertTrue($answer['api_key']['from_config']);
        self::assertStringNotContainsString('ak_test_secret', (string) json_encode($answer, JSON_UNESCAPED_UNICODE));
    }

    public function testAnUnknownReportIsNotAsked(): void
    {
        Functions\expect('wp_remote_get')->never();

        $error = analytics_connector_rest_report(new \WP_REST_Request(['name' => 'cohorts']));

        self::assertSame(['status' => 404], $error->get_error_data());
    }

    public function testAReportKeepsOnlyWhatTheScreenNeeds(): void
    {
        $this->configure();
        if (!\defined('ANALYTICS_CONNECTOR_API_KEY')) {
            \define('ANALYTICS_CONNECTOR_API_KEY', 'ak_test_secret');
        }
        $asked = null;
        Functions\when('wp_remote_get')->alias(static function (string $url) use (&$asked): array {
            $asked = $url;

            return ['response' => ['code' => 200], 'body' => json_encode(['data' => ['rows' => []], 'meta' => ['site_id' => 7, 'range' => ['from' => 'a', 'to' => 'b'], 'cache' => 'hit']])];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn (array $r): string => $r['body']);

        $answer = analytics_connector_rest_report(new \WP_REST_Request(['name' => 'pages'], ['period' => '30d', 'kind' => 'top', 'limit' => '8', 'site_id' => '1']));

        self::assertStringEndsWith('/reports/pages?kind=top&limit=8&period=30d', (string) $asked);
        self::assertSame(['data' => ['rows' => []], 'meta' => ['range' => ['from' => 'a', 'to' => 'b']]], $answer);
    }
}
