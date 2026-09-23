<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

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

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSanitisingOutsideTheSettingsApiDoesNotNeedIt(): void
    {
        // In a REST request wp-admin/includes/template.php is not loaded; the route
        // validates the key itself, and sanitising must not fatal without add_settings_error.
        $this->configure();

        self::assertFalse(\function_exists('add_settings_error'));
        self::assertSame(self::KEY, $this->sanitize(['public_key' => 'pk_nope'])['public_key']);
    }

    public function testCapabilitiesGoToAdministratorsAndEditors(): void
    {
        $granted = [];
        Functions\when('get_role')->alias(static function (string $name) use (&$granted): ?object {
            if ($name === 'subscriber') {
                return null;
            }

            return new class ($name, $granted) {
                /** @param array<string, list<string>> $granted */
                public function __construct(private string $name, private array &$granted)
                {
                }

                public function add_cap(string $cap): void
                {
                    $this->granted[$this->name][] = $cap;
                }
            };
        });

        analytics_connector_install_capabilities();

        self::assertSame(['administrator' => ['analytics_view', 'analytics_manage'], 'editor' => ['analytics_view']], $granted);
        self::assertSame('1.0.0', $this->options['analytics_connector_version']);
    }

    public function testCapabilitiesAreInstalledOnceAfterAnUpdate(): void
    {
        Functions\expect('get_role')->twice()->andReturn(null);
        analytics_connector_maybe_upgrade();
        analytics_connector_maybe_upgrade();

        self::assertSame('1.0.0', $this->options['analytics_connector_version']);
    }
}
