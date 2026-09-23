<?php

declare(strict_types=1);

namespace Analytics\Tests\Functional\Consent;

use Analytics\Consent\Application\BannerStylesheet;
use Analytics\Consent\Application\ConsentService;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Identity\Domain\SiteRole;
use Analytics\Tests\Support\HttpTestCase;

/**
 * POST /sites/{siteId}/consent/preview: the dashboard preview gets exactly the consent block the
 * tracker would serve for an unsaved configuration, or the 422 saving it would give. Requests and
 * responses are validated against docs/api/openapi.yaml.
 */
final class ConsentPreviewApiTest extends HttpTestCase
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

    /**
     * @param array<string, mixed> $theme
     *
     * @return array<string, mixed>
     */
    private static function body(array $theme): array
    {
        return ['theme' => $theme] + ConsentService::defaults();
    }

    public function testThePreviewIsTheBlockTheTrackerWouldServeOncePublished(): void
    {
        [$base, $siteId] = $this->admin();
        $body = self::body([
            'colors' => ['accent' => '#0f766e'],
            'layout' => ['mobile' => ['position' => 'sheet']],
            'reopen' => ['icon' => 'shield', 'mobile' => ['variant' => 'icon']],
            'css' => '.button { font-weight: 700 }',
        ]);
        $preview = $this->data($this->post($base . '/preview', $body));

        self::assertSame([1, 1], [$preview['v'], $preview['rev']], 'first publication: version 1, revision 1');
        self::assertSame(BannerStylesheet::ICON_PATHS['shield'], $preview['ri']);
        self::assertStringEndsWith('.k{font-weight:700}', $preview['css']);
        self::assertSame('https://www.example.com/it/privacy', $preview['texts']['it']['policyUrl']);
        self::assertSame(['en', 'it'], array_keys($preview['texts']));

        // Nothing was stored…
        $state = $this->data($this->get($base));
        self::assertNull($state['draft']);
        self::assertNull($state['published']);

        // …and saving + publishing the same body serves the very same block.
        $this->data($this->put($base . '/draft', $body));
        $this->data($this->post($base . '/publish', ['material_change' => false]));
        self::assertSame($preview, $this->service(ConsentService::class)->trackerConfig($siteId));
    }

    public function testVersionAndRevisionFollowTheDraftAndThePublishedConfiguration(): void
    {
        [$base] = $this->admin();
        $this->data($this->put($base . '/draft', self::body([])));
        $this->data($this->post($base . '/publish', ['material_change' => true]));

        $preview = $this->data($this->post($base . '/preview', self::body([])));
        self::assertSame([1, 2], [$preview['v'], $preview['rev']], 'no draft yet: the next revision');

        $this->data($this->put($base . '/draft', self::body([])));
        $preview = $this->data($this->post($base . '/preview', self::body([])));
        self::assertSame([1, 2], [$preview['v'], $preview['rev']], 'the draft revision');
    }

    public function testAV1ThemeIsPreviewedUpgraded(): void
    {
        [$base] = $this->admin();
        $v1 = ['bg' => '#ffffff', 'fg' => '#111827', 'ac' => '#1d4ed8', 'acf' => '#ffffff', 'rad' => 12, 'pos' => 'bottom-right'];
        $preview = $this->data($this->post($base . '/preview', self::body($v1)));

        self::assertSame(BannerStylesheet::compile(ConsentThemeV2::fromV1($v1)), $preview['css']);
    }

    public function testThePreviewFailsLikeSavingWould(): void
    {
        [$base] = $this->admin();
        $body = self::body([
            'colors' => ['text' => '#eeeeee'],
            'css' => ".banner { max-width: 480px }\n.button:first-child { font-size: 20px }",
        ]);
        $preview = $this->post($base . '/preview', $body);
        $this->assertProblem($preview, 422, 'validation_failed');
        $save = $this->put($base . '/draft', $body);
        $this->assertProblem($save, 422, 'validation_failed');
        self::assertSame($this->json($save)['errors'], $this->json($preview)['errors']);
        self::assertArrayHasKey('theme.colors.text', $this->json($preview)['errors']);

        $css = $this->post($base . '/preview', self::body(['css' => ".banner { max-width: 480px }\n.button:first-child { font-size: 20px }"]));
        $this->assertProblem($css, 422, 'validation_failed');
        self::assertStringStartsWith('Line 2, column 1: ', $this->json($css)['errors']['theme.css'][0]);

        $this->assertProblem($this->post($base . '/preview', ['texts' => []] + ConsentService::defaults()), 422, 'validation_failed');
    }

    public function testViewersCannotPreview(): void
    {
        $site = $this->factory->site(['cookieLevelEnabled' => true]);
        $viewer = $this->factory->user();
        $this->factory->grant($viewer, $site, SiteRole::Viewer);
        $this->loginAs($viewer);

        $this->assertProblem($this->post('/api/v1/sites/' . $site->id() . '/consent/preview', self::body([])), 403, 'forbidden');
    }
}
