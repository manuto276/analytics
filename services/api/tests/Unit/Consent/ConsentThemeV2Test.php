<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Consent;

use Analytics\Consent\Domain\ConsentThemeV2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentThemeV2Test extends TestCase
{
    private const array V1 = ['bg' => '#FFFFFF', 'fg' => '#111827', 'ac' => '#1d4ed8', 'acf' => '#ffffff', 'rad' => 12, 'pos' => 'bottom-right'];

    public function testV1IsUpgradedKeepingWhatItRendered(): void
    {
        $theme = ConsentThemeV2::fromV1(self::V1);

        self::assertSame('#ffffff', $theme->background, 'colours are normalised to lower case');
        self::assertSame('#111827', $theme->text);
        self::assertSame('#1d4ed8', $theme->accent);
        self::assertSame('#ffffff', $theme->accentText);
        self::assertSame('#111827', $theme->link, 'v1 links inherited the text colour');
        self::assertSame(12, $theme->radius);
        self::assertSame(12, $theme->buttonRadius, 'v1 used one radius for the box and the buttons');
        self::assertSame('inherit', $theme->fontFamily);
        self::assertNull($theme->fontSize, 'v1 inherited the page font size');
        self::assertSame(1.5, $theme->lineHeight);
        self::assertSame('md', $theme->shadow);
        self::assertSame(0, $theme->borderWidth);
        self::assertSame(['position' => 'bottom-right', 'maxWidth' => 576, 'offset' => 16, 'buttons' => 'row'], $theme->desktop->toArray());
        self::assertSame(['position' => 'bottom', 'offset' => 16, 'buttons' => 'row'], $theme->mobile->toArray(), 'phones get the full-width layout v1 lacked');
        self::assertSame(640, $theme->breakpoint);
        self::assertSame(['variant' => 'text', 'position' => 'bottom-right', 'offset' => 16], $theme->reopenDesktop->toArray(), 'the floating button follows bottom-right, as in v1');
        self::assertSame('text', $theme->reopenMobile->variant, 'v1 showed the text pill on every device');
        self::assertSame('', $theme->css);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function v1Positions(): iterable
    {
        yield 'bottom' => [['pos' => 'bottom'], 'bottom', 'bottom-left'];
        yield 'bottom-left' => [['pos' => 'bottom-left'], 'bottom-left', 'bottom-left'];
        yield 'bottom-right' => [['pos' => 'bottom-right'], 'bottom-right', 'bottom-right'];
        yield 'missing' => [[], 'bottom', 'bottom-left'];
        yield 'unknown' => [['pos' => 'middle'], 'bottom', 'bottom-left'];
    }

    /** @param array<string, mixed> $patch */
    #[DataProvider('v1Positions')]
    public function testV1Positions(array $patch, string $desktop, string $reopen): void
    {
        $v1 = $patch + self::V1;
        if ($patch === []) {
            unset($v1['pos']);
        }
        $theme = ConsentThemeV2::fromV1($v1);
        self::assertSame($desktop, $theme->desktop->position);
        self::assertSame($reopen, $theme->reopenDesktop->position);
        self::assertSame('bottom', $theme->mobile->position);
    }

    public function testBrokenV1ValuesFallBackToDefaults(): void
    {
        $theme = ConsentThemeV2::fromV1(['bg' => 'red;}*{display:none', 'fg' => '#12', 'rad' => 500, 'ac' => null]);
        self::assertSame('#ffffff', $theme->background);
        self::assertSame('#111827', $theme->text);
        self::assertSame(32, $theme->radius, 'clamped');
        self::assertSame('#1d4ed8', $theme->accent);
        self::assertSame(0, ConsentThemeV2::fromV1(['bg' => '#000000', 'rad' => -3])->radius);
    }

    public function testShapeDetection(): void
    {
        self::assertTrue(ConsentThemeV2::isV1(self::V1));
        self::assertTrue(ConsentThemeV2::isV1(['pos' => 'bottom']));
        self::assertFalse(ConsentThemeV2::isV1([]));
        self::assertFalse(ConsentThemeV2::isV1(['colors' => ['text' => '#000000']]));
        self::assertFalse(ConsentThemeV2::isV1(['bg' => '#000000', 'colors' => []]), 'mixed shapes are not v1');
    }

    public function testFromStoredReadsBothShapes(): void
    {
        self::assertEquals(ConsentThemeV2::fromV1(self::V1), ConsentThemeV2::fromStored(self::V1));
        self::assertEquals(ConsentThemeV2::defaults(), ConsentThemeV2::fromStored([]));
        $full = ConsentThemeV2::defaults()->toArray();
        self::assertEquals(ConsentThemeV2::defaults(), ConsentThemeV2::fromStored($full), 'toArray/fromStored round-trip');
    }

    public function testFromStoredMergesAPartialV2ThemeAndIgnoresInvalidValues(): void
    {
        $theme = ConsentThemeV2::fromStored([
            'colors' => ['text' => '#000000', 'accent' => 'blue', 'backdrop' => '#00000080'],
            'font' => ['family' => 'url(evil)', 'size' => 99, 'lineHeight' => 1.25],
            'shape' => ['radius' => 4, 'buttonRadius' => 'x'],
            'layout' => ['breakpoint' => 800, 'desktop' => ['position' => 'center', 'maxWidth' => 5000], 'mobile' => ['position' => 'sheet']],
            'reopen' => ['icon' => 'skull', 'desktop' => ['variant' => 'hidden'], 'background' => '#123456'],
            'shadow' => 'huge',
            'css' => '.button{font-weight:700}',
        ]);
        self::assertSame('#000000', $theme->text);
        self::assertSame('#1d4ed8', $theme->accent);
        self::assertSame('#00000080', $theme->backdrop);
        self::assertSame('inherit', $theme->fontFamily);
        self::assertNull($theme->fontSize);
        self::assertSame(1.25, $theme->lineHeight);
        self::assertSame(4, $theme->radius);
        self::assertSame(8, $theme->buttonRadius);
        self::assertSame(800, $theme->breakpoint);
        self::assertSame('center', $theme->desktop->position);
        self::assertSame(576, $theme->desktop->maxWidth);
        self::assertSame('sheet', $theme->mobile->position);
        self::assertSame('cookie', $theme->reopenIcon);
        self::assertSame('hidden', $theme->reopenDesktop->variant);
        self::assertSame('#123456', $theme->reopenBackgroundColor());
        self::assertSame('#ffffff', $theme->reopenTextColor(), 'defaults to the text on accent');
        self::assertSame('md', $theme->shadow);
        self::assertSame('.button{font-weight:700}', $theme->css);
        self::assertSame('', ConsentThemeV2::fromStored(['css' => str_repeat('a', ConsentThemeV2::CSS_MAX_BYTES + 1)])->css);
    }

    public function testToArrayHasTheSchemaShape(): void
    {
        $array = ConsentThemeV2::defaults()->toArray();
        self::assertSame(ConsentThemeV2::V2_KEYS, array_keys($array));
        self::assertSame(['background', 'text', 'accent', 'accentText', 'border', 'link', 'backdrop'], array_keys($array['colors']));
        $schema = json_decode((string) file_get_contents(\dirname(__DIR__, 5) . '/docs/api/consent-theme.v2.schema.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        self::assertSame(array_keys($schema['properties']), array_keys($array), 'the schema documents exactly these fields');
        foreach (['colors', 'font', 'shape', 'spacing', 'border'] as $group) {
            self::assertSame(array_keys($schema['properties'][$group]['properties']), array_keys($array[$group]), $group);
        }
        self::assertSame(ConsentThemeV2::DESKTOP_POSITIONS, $schema['properties']['layout']['properties']['desktop']['properties']['position']['enum']);
        self::assertSame(ConsentThemeV2::MOBILE_POSITIONS, $schema['properties']['layout']['properties']['mobile']['properties']['position']['enum']);
        self::assertSame(ConsentThemeV2::REOPEN_ICONS, $schema['properties']['reopen']['properties']['icon']['enum']);
        self::assertSame(ConsentThemeV2::REOPEN_VARIANTS, $schema['$defs']['reopenPlacement']['properties']['variant']['enum']);
        foreach (ConsentThemeV2::RANGES as $path => [$min, $max]) {
            $node = ['properties' => $schema['properties']];
            foreach (explode('.', $path) as $key) {
                $node = $node['properties'][$key] ?? $schema['$defs']['reopenPlacement']['properties'][$key];
            }
            self::assertSame([$min, $max], [$node['minimum'], $node['maximum']], $path);
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function fontStacks(): iterable
    {
        yield 'inherit' => ['inherit', 'inherit'];
        yield 'system' => ['system', 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif'];
        yield 'names and generics' => ['Inter, "Helvetica Neue", Arial, sans-serif', '"Inter","Helvetica Neue","Arial",sans-serif'];
        yield 'single quotes' => ["'Open Sans', serif", '"Open Sans",serif'];
        yield 'url' => ['url(https://evil.test/font.woff)', null];
        yield 'rule break' => ['Arial;}*{display:none', null];
        yield 'escape' => ['Ar\\69 al', null];
        yield 'empty item' => ['Arial,,serif', null];
        yield 'unbalanced quote' => ['"Arial', null];
        yield 'too long' => [str_repeat('A', 201), null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('fontStacks')]
    public function testFontStack(string $family, ?string $css): void
    {
        self::assertSame($css, ConsentThemeV2::fontStack($family));
    }
}
