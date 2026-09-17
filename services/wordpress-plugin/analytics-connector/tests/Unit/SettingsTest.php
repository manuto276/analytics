<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class SettingsTest extends TestCase
{
    /**
     * @param array<string, string> $input
     * @return array<string, string>
     */
    private function sanitize(array $input): array
    {
        return analytics_connector_sanitize_settings($input);
    }

    public function testValidInputIsKeptAndServiceUrlLosesTrailingSlash(): void
    {
        Functions\expect('add_settings_error')->never();

        self::assertSame([
            'service_url' => 'https://stats.example.net',
            'public_key' => self::KEY,
            'mode' => 'proxy',
            'proxy_path' => '/stats/',
            'skip_capability' => 'manage_options',
            'global_name' => '__stats',
        ], $this->sanitize([
            'service_url' => '  https://stats.example.net/ ',
            'public_key' => ' ' . self::KEY . ' ',
            'mode' => 'proxy',
            'proxy_path' => 'stats',
            'skip_capability' => 'manage_options',
            'global_name' => '__stats',
        ]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'wrong prefix' => ['sk_AbCdEfGhIjKlMnOpQrStU'];
        yield 'too short' => ['pk_AbCdEf'];
        yield 'too long' => [self::KEY . 'V'];
        yield 'symbols' => ['pk_AbCdEfGhIjKlMnOpQr-_U'];
        yield 'markup' => ['<script>alert(1)</script>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testInvalidPublicKeyIsRejectedAndPreviousKept(string $key): void
    {
        $this->configure();
        Functions\expect('add_settings_error')->once()->with('analytics_connector_settings', 'invalid_public_key', \Mockery::type('string'));

        self::assertSame(self::KEY, $this->sanitize(['public_key' => $key])['public_key']);
    }

    public function testPublicKeyValidatorIsAnchored(): void
    {
        self::assertTrue(analytics_connector_is_valid_public_key(self::KEY));
        self::assertFalse(analytics_connector_is_valid_public_key(self::KEY . "\n"));
        self::assertFalse(analytics_connector_is_valid_public_key(null));
    }

    public function testInvalidPublicKeyWithoutPreviousValueIsEmpty(): void
    {
        Functions\expect('add_settings_error')->once();

        self::assertSame('', $this->sanitize(['public_key' => 'pk_nope'])['public_key']);
    }

    public function testUnsafeValuesAreNormalised(): void
    {
        $out = $this->sanitize([
            'mode' => 'evil',
            'proxy_path' => '/st"ats/../x<y>/',
            'skip_capability' => 'Edit Posts!',
            'global_name' => 'a-b;alert(1)',
        ]);

        self::assertSame('direct', $out['mode']);
        self::assertSame('/stats/xy/', $out['proxy_path']);
        self::assertSame('editposts', $out['skip_capability']);
        self::assertSame('analytics', $out['global_name']);
    }

    public function testEmptyValuesAreAllowed(): void
    {
        $out = analytics_connector_sanitize_settings('not an array');

        self::assertSame('', $out['service_url']);
        self::assertSame('', $out['public_key']);
        self::assertSame('', $out['proxy_path']);
        self::assertSame('', $out['skip_capability'], 'empty capability means track everyone');
        self::assertSame('analytics', $out['global_name']);
    }

    public function testDefaultsWhenNothingStored(): void
    {
        self::assertSame(analytics_connector_default_settings(), analytics_connector_get_settings());
        self::assertSame('edit_posts', analytics_connector_get_settings()['skip_capability']);
    }

    public function testRegisterSettingUsesSanitizeCallback(): void
    {
        Functions\expect('register_setting')->once()->with(
            'analytics_connector',
            'analytics_connector_settings',
            \Mockery::on(static fn (array $args): bool => $args['sanitize_callback'] === 'analytics_connector_sanitize_settings'),
        );

        analytics_connector_register_setting();
    }

    public function testSettingsPageRequiresManageOptions(): void
    {
        Functions\expect('add_options_page')->once()->with('Analytics', 'Analytics', 'manage_options', 'analytics-connector', 'analytics_connector_render_settings_page');
        analytics_connector_add_settings_page();

        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(false);
        $this->expectOutputString('');
        analytics_connector_render_settings_page();
    }

    public function testSettingsPageRendersNonceFieldsAndEscapedValues(): void
    {
        $this->configure(['service_url' => 'https://stats.example.net/"><script>']);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_admin_page_title')->justReturn('Analytics');
        Functions\expect('settings_fields')->once()->with('analytics_connector');
        Functions\when('checked')->alias(static function (string $a, string $b): void {
            echo $a === $b ? "checked='checked'" : '';
        });
        Functions\when('submit_button')->justReturn(null);

        ob_start();
        analytics_connector_render_settings_page();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('action="options.php"', $html);
        self::assertStringContainsString('value="direct" checked=\'checked\'', $html);
        self::assertStringNotContainsString('"><script>', $html);
        self::assertStringContainsString('name="analytics_connector_settings[public_key]" value="' . self::KEY . '"', $html);
    }

    public function testPluginFileRegistersHooks(): void
    {
        Actions\expectAdded('admin_init')->once()->with('analytics_connector_register_setting');
        Actions\expectAdded('admin_menu')->once()->with('analytics_connector_add_settings_page');
        Actions\expectAdded('init')->once()->with('analytics_connector_init');
        Actions\expectAdded('wp_enqueue_scripts')->once()->with('analytics_connector_enqueue_scripts');
        Actions\expectAdded('wp_head')->once()->with('analytics_connector_content_meta', 1);
        Filters\expectAdded('nav_menu_link_attributes')->once()->with('analytics_connector_menu_link_attributes');

        require dirname(__DIR__, 2) . '/analytics-connector.php';
    }
}
