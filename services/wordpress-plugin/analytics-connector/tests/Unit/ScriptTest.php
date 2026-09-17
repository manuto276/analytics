<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Functions;

final class ScriptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('is_user_logged_in')->justReturn(false);
    }

    public function testDirectModeEnqueuesDeferredScriptFromService(): void
    {
        $this->configure();

        Functions\expect('wp_enqueue_script')->once()->with(
            'analytics-connector',
            'https://stats.example.net/t/' . self::KEY . '.js',
            [],
            null,
            ['strategy' => 'defer', 'in_footer' => false],
        );
        Functions\expect('wp_add_inline_script')->once()->with(
            'analytics-connector',
            "window.analytics=window.analytics||{q:[],track(){this.q.push(['track',...arguments])}}",
            'before',
        );

        analytics_connector_enqueue_scripts();
    }

    public function testProxyModeLoadsScriptFromFirstPartyPath(): void
    {
        $this->configure(['mode' => 'proxy', 'proxy_path' => '/stats/', 'service_url' => '']);

        Functions\expect('wp_enqueue_script')->once()->with(
            'analytics-connector',
            'https://www.example.com/stats/' . self::KEY . '.js',
            [],
            null,
            ['strategy' => 'defer', 'in_footer' => false],
        );
        Functions\expect('wp_add_inline_script')->once();

        analytics_connector_enqueue_scripts();
    }

    public function testInlineStubUsesConfiguredGlobalName(): void
    {
        $this->configure(['global_name' => 'siteStats']);

        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\expect('wp_add_inline_script')->once()->with(
            'analytics-connector',
            "window.siteStats=window.siteStats||{q:[],track(){this.q.push(['track',...arguments])}}",
            'before',
        );

        analytics_connector_enqueue_scripts();
    }

    public function testInvalidStoredGlobalNameFallsBackToDefault(): void
    {
        $this->configure(['global_name' => 'x;alert(1)']);

        Functions\when('wp_enqueue_script')->justReturn(null);
        Functions\expect('wp_add_inline_script')->once()->with(
            'analytics-connector',
            \Mockery::on(static fn (string $js): bool => str_starts_with($js, 'window.analytics=window.analytics||')),
            'before',
        );

        analytics_connector_enqueue_scripts();
    }

    public function testSkippedForLoggedInUserWithCapability(): void
    {
        $this->configure();
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_add_inline_script')->never();

        analytics_connector_enqueue_scripts();
    }

    public function testLoggedInUserWithoutCapabilityIsTracked(): void
    {
        $this->configure();
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_add_inline_script')->once();

        analytics_connector_enqueue_scripts();
    }

    public function testEmptyCapabilityTracksEveryone(): void
    {
        $this->configure(['skip_capability' => '']);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\expect('current_user_can')->never();
        Functions\expect('wp_enqueue_script')->once();
        Functions\expect('wp_add_inline_script')->once();

        analytics_connector_enqueue_scripts();
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unconfigured(): iterable
    {
        yield 'no options' => [[]];
        yield 'invalid key' => [['service_url' => 'https://stats.example.net', 'public_key' => 'pk_short']];
        yield 'direct without service url' => [['public_key' => self::KEY]];
        yield 'proxy without path' => [['public_key' => self::KEY, 'mode' => 'proxy', 'proxy_path' => '']];
    }

    /**
     * @param array<string, string> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unconfigured')]
    public function testNothingEnqueuedWhenNotConfigured(array $options): void
    {
        if ($options !== []) {
            $this->options['analytics_connector_settings'] = $options;
        }
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_add_inline_script')->never();

        analytics_connector_enqueue_scripts();
        $this->addToAssertionCount(1);
    }
}
