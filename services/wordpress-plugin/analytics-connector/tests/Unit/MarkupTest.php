<?php

declare(strict_types=1);

namespace AnalyticsConnector\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

final class MarkupTest extends TestCase
{
    public function testShortcodeRendersConsentLinkWithEscapedLabel(): void
    {
        self::assertSame(
            '<a href="#analytics-consent" data-analytics-consent>&lt;b&gt;Privacy &quot;prefs&quot;&lt;/b&gt;</a>',
            analytics_connector_consent_link_shortcode(['label' => '<b>Privacy "prefs"</b>']),
        );
    }

    public function testShortcodeWithoutLabelUsesDefault(): void
    {
        self::assertSame(
            '<a href="#analytics-consent" data-analytics-consent>Cookie settings</a>',
            analytics_connector_consent_link_shortcode(''),
        );
    }

    public function testBlockRendersThroughShortcode(): void
    {
        self::assertSame(
            '<a href="#analytics-consent" data-analytics-consent>Manage cookies</a>',
            analytics_connector_render_consent_block(['label' => 'Manage cookies']),
        );
        self::assertStringContainsString('>Cookie settings<', analytics_connector_render_consent_block([]));
    }

    public function testInitRegistersShortcodeAndBlock(): void
    {
        Functions\when('plugin_basename')->justReturn('analytics-connector/analytics-connector.php');
        Functions\expect('load_plugin_textdomain')->once()->with('analytics-connector', false, 'analytics-connector/languages');
        Functions\expect('add_shortcode')->once()->with('analytics_consent_link', 'analytics_connector_consent_link_shortcode');
        Functions\expect('register_block_type')->once()->with(
            \Mockery::on(static fn (string $path): bool => is_file($path . '/block.json')),
            ['render_callback' => 'analytics_connector_render_consent_block'],
        )->andReturn((object) ['editor_script_handles' => ['analytics-connector-consent-link-editor-script']]);
        Functions\expect('wp_set_script_translations')->once()->with('analytics-connector-consent-link-editor-script', 'analytics-connector', \Mockery::type('string'));

        analytics_connector_init();
    }

    public function testBlockMetadataIsValid(): void
    {
        $meta = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/blocks/consent-link/block.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('analytics-connector/consent-link', $meta['name']);
        self::assertSame('file:./editor.js', $meta['editorScript']);
    }

    public function testMenuLinkToConsentAnchorGetsDataAttribute(): void
    {
        self::assertSame(
            ['href' => '#analytics-consent', 'data-analytics-consent' => 'true'],
            analytics_connector_menu_link_attributes(['href' => '#analytics-consent']),
        );
        self::assertSame(
            ['href' => 'https://www.example.com/#analytics-consent', 'data-analytics-consent' => 'true'],
            analytics_connector_menu_link_attributes(['href' => 'https://www.example.com/#analytics-consent']),
        );
    }

    public function testOtherMenuLinksAreUntouched(): void
    {
        self::assertSame(['href' => 'https://www.example.com/about/'], analytics_connector_menu_link_attributes(['href' => 'https://www.example.com/about/']));
        self::assertSame(['title' => 'x'], analytics_connector_menu_link_attributes(['title' => 'x']));
    }

    public function testContentMetaPrintsFilteredKeyEscaped(): void
    {
        Filters\expectApplied('analytics_connector_content_key')->once()->with(null)->andReturn('author:42" onload="x');

        $this->expectOutputString('<meta name="analytics:content" content="author:42&quot; onload=&quot;x">' . "\n");
        analytics_connector_content_meta();
    }

    public function testContentMetaPrintsNothingByDefault(): void
    {
        $this->expectOutputString('');
        analytics_connector_content_meta();
    }

    public function testContentMetaPrintsNothingForEmptyString(): void
    {
        Filters\expectApplied('analytics_connector_content_key')->once()->andReturn('');

        $this->expectOutputString('');
        analytics_connector_content_meta();
    }
}
