<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Consent;

use Analytics\Consent\Application\BannerStylesheet;
use Analytics\Consent\Application\ConsentService;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Shared\Validation\Input;
use Analytics\Tests\Support\HttpTestCase;

/**
 * Theme v2 through the API (requests and responses are validated against docs/api/openapi.yaml),
 * v1 compatibility, and what reaches the tracker config.
 */
final class ConsentThemeApiTest extends HttpTestCase
{
    /** @return array{string, int} base URL, site id */
    private function admin(): array
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $admin = $this->factory->user();
        $this->factory->grant($admin, $site, SiteRole::Admin);
        $this->loginAs($admin);

        return ['/api/v1/sites/' . $site->id() . '/consent', $site->id()];
    }

    /** @param array<string, mixed> $theme */
    private static function draft(array $theme): array
    {
        return ['theme' => $theme] + ConsentService::defaults();
    }

    public function testAPartialV2ThemeIsStoredCompleteAndServedCompiled(): void
    {
        [$base, $siteId] = $this->admin();
        $draft = $this->data($this->put($base . '/draft', self::draft([
            'colors' => ['accent' => '#0F766E', 'accentText' => '#FFFFFF'],
            'layout' => ['desktop' => ['position' => 'bottom-left'], 'mobile' => ['position' => 'sheet']],
            'reopen' => ['icon' => 'fingerprint', 'mobile' => ['variant' => 'icon', 'position' => 'bottom-right']],
            'css' => ".button {\n  font-weight: 700;\n}",
        ])));

        $expected = ConsentThemeV2::defaults()->toArray();
        $expected['colors']['accent'] = '#0f766e';
        $expected['layout']['desktop']['position'] = 'bottom-left';
        $expected['layout']['mobile']['position'] = 'sheet';
        $expected['reopen']['icon'] = 'fingerprint';
        $expected['reopen']['mobile']['position'] = 'bottom-right';
        $expected['css'] = ".button {\n  font-weight: 700;\n}";
        self::assertEquals($expected, $draft['theme'], 'stored complete, colours lower-cased, CSS untouched');
        self::assertEquals($expected, $draft['theme_v2']);

        $this->data($this->post($base . '/publish', ['material_change' => false]));
        $config = $this->service(ConsentService::class)->trackerConfig($siteId);
        self::assertIsArray($config);
        self::assertArrayNotHasKey('theme', $config, 'the tracker only needs the compiled stylesheet');
        self::assertIsString($config['css']);
        self::assertStringContainsString('.k{flex:1;margin:0;font:inherit;padding:8px 16px;cursor:pointer;border:2px solid #0f766e', $config['css']);
        self::assertStringEndsWith('.k{font-weight:700}', $config['css']);
        self::assertSame(BannerStylesheet::ICON_PATHS['fingerprint'], $config['ri']);
    }

    public function testV1ThemesAreStillAcceptedAndUpgradedOnRead(): void
    {
        [$base, $siteId] = $this->admin();
        $v1 = ['bg' => '#ffffff', 'fg' => '#111827', 'ac' => '#1d4ed8', 'acf' => '#ffffff', 'rad' => 12, 'pos' => 'bottom-right'];
        $draft = $this->data($this->put($base . '/draft', self::draft($v1)));
        self::assertSame($v1, $draft['theme'], 'a v1 theme is stored as sent');
        self::assertEquals(ConsentThemeV2::fromV1($v1)->toArray(), $draft['theme_v2']);

        $this->data($this->post($base . '/publish', ['material_change' => false]));
        $config = $this->service(ConsentService::class)->trackerConfig($siteId);
        self::assertIsArray($config);
        self::assertSame(BannerStylesheet::compile(ConsentThemeV2::fromV1($v1)), $config['css']);
        self::assertStringContainsString('@media (max-width:640px){.b{top:auto;bottom:calc(16px + env(safe-area-inset-bottom,0px));translate:none;left:16px;right:16px;width:auto;max-width:none;margin:0', $config['css'], 'v1 banners get the phone layout');

        $shown = $this->data($this->get($base));
        self::assertSame($v1, $shown['published']['theme']);
        self::assertSame('bottom-right', $shown['published']['theme_v2']['layout']['desktop']['position']);
        self::assertEquals(ConsentThemeV2::defaults()->toArray(), $shown['defaults']['theme'], 'the defaults are a complete v2 theme');
        self::assertEquals(ConsentThemeV2::defaults()->toArray(), $shown['defaults']['theme_v2']);
    }

    /** A theme stored by an installation that predates v2 keeps rendering, straight from the database. */
    public function testALegacyRowIsReadWithoutMigration(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $consent = $this->service(ConsentService::class);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $consent->publish($site->id(), false, 1);
        $this->db->executeStatement('UPDATE consent_configs SET theme = ? WHERE site_id = ?', ['{"bg":"#fafafa","fg":"#000000","ac":"#b91c1c","acf":"#ffffff","rad":0,"pos":"bottom-left"}', $site->id()]);
        $this->clearCaches();
        $this->em->clear();

        $config = $consent->trackerConfig($site->id());
        self::assertIsArray($config);
        self::assertIsString($config['css']);
        self::assertStringContainsString('.b{color:#000000;background:#fafafa;', $config['css']);
        self::assertStringContainsString('left:16px;right:auto;width:min(576px,calc(100% - 32px))', $config['css']);
        self::assertStringContainsString('border-radius:0px;background:#b91c1c', $config['css']);
    }

    public function testThemeEditsNeverBumpTheConsentVersion(): void
    {
        [$base] = $this->admin();
        $this->data($this->put($base . '/draft', self::draft([])));
        self::assertSame(1, $this->data($this->post($base . '/publish', ['material_change' => false]))['consent_version']);
        $this->data($this->put($base . '/draft', self::draft(['colors' => ['background' => '#f8fafc'], 'css' => '.title { font-size: 20px }'])));
        $republished = $this->data($this->post($base . '/publish', ['material_change' => false]));
        self::assertSame([1, 2], [$republished['consent_version'], $republished['revision']], 'same consent version, next revision');
    }

    public function testValidationNamesEveryField(): void
    {
        [$base] = $this->admin();
        $response = $this->put($base . '/draft', self::draft([
            'colors' => ['background' => '#ffffff', 'text' => '#eeeeee', 'accent' => '#1d4ed8', 'accentText' => '#1d4ed9', 'link' => '#dddddd'],
            'reopen' => ['background' => '#ffffff', 'text' => '#fefefe'],
        ]));
        $this->assertProblem($response, 422, 'validation_failed');
        $errors = $this->json($response)['errors'];
        self::assertSame(['theme.colors.text', 'theme.colors.accentText', 'theme.colors.link', 'theme.reopen.text'], array_keys($errors));
        self::assertStringContainsString('contrast must be at least 4.5:1', $errors['theme.colors.link'][0]);
    }

    public function testRangesEnumsAndShapesAreCheckedByTheServer(): void
    {
        $theme = new Input(['theme' => [
            'font' => ['family' => 'x;}*{display:none', 'size' => 40, 'lineHeight' => 9],
            'shape' => ['radius' => 64],
            'layout' => ['breakpoint' => 100, 'desktop' => ['position' => 'left'], 'mobile' => ['position' => 'bottom-right']],
            'reopen' => ['icon' => 'skull', 'desktop' => ['variant' => 'blink']],
            'colors' => ['backdrop' => 'rgba(0,0,0,.5)'],
            'wat' => 1,
        ]] + ConsentService::defaults());
        try {
            ConsentService::validate($theme);
            self::fail('expected a validation error');
        } catch (\Analytics\Shared\Http\ApiProblem $problem) {
            $errors = $problem->errors;
            foreach (['theme.font.family', 'theme.font.size', 'theme.font.lineHeight', 'theme.shape.radius', 'theme.layout.breakpoint', 'theme.layout.desktop.position', 'theme.layout.mobile.position', 'theme.reopen.icon', 'theme.reopen.desktop.variant', 'theme.colors.backdrop', 'theme.wat'] as $field) {
                self::assertArrayHasKey($field, $errors, $field);
            }
        }

        $mixed = new Input(['theme' => ['bg' => '#ffffff', 'colors' => []]] + ConsentService::defaults());
        try {
            ConsentService::validate($mixed);
            self::fail('expected a validation error');
        } catch (\Analytics\Shared\Http\ApiProblem $problem) {
            self::assertStringContainsString('not both', $problem->errors['theme.bg'][0]);
        }
    }

    public function testCustomCssErrorsComeBackWithLineAndColumn(): void
    {
        [$base] = $this->admin();
        $response = $this->put($base . '/draft', self::draft(['css' => ".banner { max-width: 480px }\n.button:first-child { font-size: 20px }\n.banner {\n  display: none;\n}\n@import url(https://evil.test/x.css);"]));
        $this->assertProblem($response, 422, 'validation_failed');
        $errors = $this->json($response)['errors']['theme.css'];
        self::assertCount(3, $errors);
        self::assertStringStartsWith('Line 2, column 1: Selector ".button:first-child"', $errors[0]);
        self::assertStringStartsWith('Line 4, column 3: The property "display" is not allowed', $errors[1]);
        self::assertStringStartsWith('Line 6, column 1: "@import" is not allowed', $errors[2]);

        $long = $this->put($base . '/draft', self::draft(['css' => '/*' . str_repeat('x', 8200) . '*/']));
        $this->assertProblem($long, 422, 'validation_failed');
    }

    public function testCustomCssContrastIsCheckedAgainstTheTheme(): void
    {
        [$base] = $this->admin();
        $response = $this->put($base . '/draft', self::draft([
            'colors' => ['background' => '#111827', 'text' => '#f9fafb', 'link' => '#93c5fd'],
            'css' => '.body { color: #4b5563 }',
        ]));
        $this->assertProblem($response, 422, 'validation_failed');
        self::assertStringContainsString('.body: text/background contrast is', $this->json($response)['errors']['theme.css'][0]);
    }

    public function testATrackerConfigCachedBeforeTheStylesheetIsRebuilt(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $consent = $this->service(ConsentService::class);
        $consent->saveDraft($site->id(), new Input(ConsentService::defaults()));
        $consent->publish($site->id(), false, 1);
        $pool = $this->container->get(\Psr\Cache\CacheItemPoolInterface::class);
        \assert($pool instanceof \Psr\Cache\CacheItemPoolInterface);
        // What the previous release cached: the v1 theme, no stylesheet.
        $pool->save($pool->getItem(ConsentService::TRACKER_CONFIG_CACHE . $site->id())->set(['v' => 1, 'theme' => ['bg' => '#ffffff'], 'texts' => []]));

        $config = $consent->trackerConfig($site->id());
        self::assertIsArray($config);
        self::assertArrayHasKey('css', $config);
        self::assertArrayNotHasKey('theme', $config);
    }
}
