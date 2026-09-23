<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Consent;

use Analytics\Consent\Application\BannerStylesheet;
use Analytics\Consent\Domain\ConsentThemeV2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Snapshots of the compiled banner stylesheet (tests/fixtures/banner-css/*.css, one rule per line
 * for readable diffs). After an intended change: UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter BannerStylesheetTest
 */
final class BannerStylesheetTest extends TestCase
{
    private const array V1 = ['bg' => '#ffffff', 'fg' => '#111827', 'ac' => '#1d4ed8', 'acf' => '#ffffff', 'rad' => 8, 'pos' => 'bottom-right'];

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function snapshots(): iterable
    {
        yield 'v1-bottom-right' => [self::V1];
        yield 'v2-defaults' => [[]];
        yield 'v2-custom' => [[
            'colors' => ['background' => '#111827', 'text' => '#f9fafb', 'accent' => '#fbbf24', 'accentText' => '#111827', 'border' => '#374151', 'link' => '#93c5fd', 'backdrop' => '#00000066'],
            'font' => ['family' => 'Inter, system-ui, sans-serif', 'size' => 15, 'lineHeight' => 1.4],
            'shape' => ['radius' => 16, 'buttonRadius' => 999],
            'spacing' => ['padding' => 24, 'gap' => 12],
            'border' => ['width' => 1],
            'shadow' => 'lg',
            'layout' => [
                'breakpoint' => 800,
                'desktop' => ['position' => 'center', 'maxWidth' => 520, 'offset' => 24, 'buttons' => 'row'],
                'mobile' => ['position' => 'sheet', 'offset' => 0, 'buttons' => 'stack'],
            ],
            'reopen' => [
                'icon' => 'shield', 'size' => 'lg', 'background' => '#f9fafb', 'text' => '#111827',
                'desktop' => ['variant' => 'icon-text', 'position' => 'bottom-right', 'offset' => 24],
                'mobile' => ['variant' => 'hidden', 'position' => 'bottom-left', 'offset' => 12],
            ],
            'css' => ".button { font-weight: 700; text-transform: uppercase }\n.button:hover { background-color: #f59e0b }",
        ]];
    }

    /** @param array<string, mixed> $theme */
    #[DataProvider('snapshots')]
    public function testSnapshot(array $theme): void
    {
        $name = (string) $this->dataName();
        $css = BannerStylesheet::compile(ConsentThemeV2::fromStored($theme));
        $pretty = (string) preg_replace('/\}(?!\})/', "}\n", $css);
        $file = \dirname(__DIR__, 2) . '/fixtures/banner-css/' . $name . '.css';
        if (getenv('UPDATE_SNAPSHOTS') === '1' || !is_file($file)) {
            @mkdir(\dirname($file), 0755, true);
            file_put_contents($file, $pretty);
        }
        self::assertSame((string) file_get_contents($file), $pretty, 'snapshot ' . $name . ' (UPDATE_SNAPSHOTS=1 after an intended change)');
    }

    public function testAcceptAndRejectShareOneRule(): void
    {
        $css = BannerStylesheet::compile(ConsentThemeV2::defaults());
        // Buttons are only ever addressed through `.k`, the class both carry (Garante 2021, B2).
        self::assertDoesNotMatchRegularExpression('/\[|:(first|last|nth|only)-|:(not|has|is|where)\(/', $css);
        self::assertStringContainsString('.k{flex:1;margin:0;font:inherit;padding:8px 16px;cursor:pointer;border:2px solid #1d4ed8;border-radius:8px;background:#1d4ed8;color:#ffffff}', $css);
    }

    /** @return iterable<string, array{string, string}> */
    public static function desktopPositions(): iterable
    {
        yield 'bottom' => ['bottom', '.b{top:auto;bottom:16px;left:16px;right:16px;width:auto;max-width:576px;margin:0 auto;max-height:calc(100% - 32px)}'];
        yield 'top' => ['top', '.b{top:16px;bottom:auto;left:16px;right:16px;width:auto;max-width:576px;margin:0 auto;max-height:calc(100% - 32px)}'];
        yield 'bottom-left' => ['bottom-left', '.b{top:auto;bottom:16px;left:16px;right:auto;width:min(576px,calc(100% - 32px));max-width:none;margin:0;max-height:calc(100% - 32px)}'];
        yield 'bottom-right' => ['bottom-right', '.b{top:auto;bottom:16px;left:auto;right:16px;width:min(576px,calc(100% - 32px));max-width:none;margin:0;max-height:calc(100% - 32px)}'];
        yield 'center' => ['center', '.b{top:50%;bottom:auto;translate:0 -50%;left:16px;right:16px;width:auto;max-width:576px;margin:0 auto;max-height:calc(100% - 32px)}'];
    }

    #[DataProvider('desktopPositions')]
    public function testDesktopPositionsSetEveryInset(string $position, string $rule): void
    {
        [$desktop] = self::split(BannerStylesheet::compile(ConsentThemeV2::fromStored(['layout' => ['desktop' => ['position' => $position]]])));
        self::assertStringContainsString($rule, $desktop);
    }

    /** @return iterable<string, array{string, string}> */
    public static function mobilePositions(): iterable
    {
        yield 'bottom' => ['bottom', '.b{top:auto;bottom:calc(12px + env(safe-area-inset-bottom,0px));translate:none;left:12px;right:12px;width:auto;max-width:none;margin:0;max-height:calc(100% - 24px);'];
        yield 'top' => ['top', '.b{top:calc(12px + env(safe-area-inset-top,0px));bottom:auto;translate:none;left:12px;right:12px;width:auto;max-width:none;margin:0;max-height:calc(100% - 24px);'];
        yield 'center' => ['center', '.b{top:50%;bottom:auto;translate:0 -50%;left:12px;right:12px;width:auto;max-width:none;margin:0;max-height:calc(100% - 24px);'];
        yield 'sheet' => ['sheet', '.b{top:auto;bottom:0;translate:none;left:0;right:0;width:auto;max-width:none;margin:0;border-radius:8px 8px 0 0;padding-bottom:calc(16px + env(safe-area-inset-bottom,0px));max-height:100%;'];
    }

    /**
     * Every mobile position spans the viewport with the same gap on both sides, whatever the
     * desktop position was (the reported bug: `bottom-right` touched the left edge on phones).
     */
    #[DataProvider('mobilePositions')]
    public function testMobilePositionsAreFullWidthWithEqualGaps(string $position, string $rule): void
    {
        foreach (ConsentThemeV2::DESKTOP_POSITIONS as $desktop) {
            [, $mobile] = self::split(BannerStylesheet::compile(ConsentThemeV2::fromStored(['layout' => ['desktop' => ['position' => $desktop], 'mobile' => ['position' => $position, 'offset' => 12]]])));
            self::assertStringContainsString($rule, $mobile, $desktop . ' → ' . $position);
        }
    }

    public function testBreakpointOpensTheMobileBlock(): void
    {
        self::assertStringContainsString('@media (max-width:640px){', BannerStylesheet::compile(ConsentThemeV2::defaults()));
        $css = BannerStylesheet::compile(ConsentThemeV2::fromStored(['layout' => ['breakpoint' => 1024]]));
        self::assertSame(1, substr_count($css, '@media (max-width:'), 'exactly one breakpoint block');
        self::assertStringContainsString('@media (max-width:1024px){', $css);
    }

    /** @return iterable<string, array{string, string}> */
    public static function reopenVariants(): iterable
    {
        yield 'text' => ['text', '.f{top:auto;bottom:calc(16px + env(safe-area-inset-bottom,0px));left:16px;right:auto;display:inline-flex;padding:8px 14px}.i{display:none}.t{display:inline}'];
        yield 'icon' => ['icon', '.f{top:auto;bottom:calc(16px + env(safe-area-inset-bottom,0px));left:16px;right:auto;display:inline-flex;padding:10px}.i{display:block}.t{display:none}'];
        yield 'icon-text' => ['icon-text', '.f{top:auto;bottom:calc(16px + env(safe-area-inset-bottom,0px));left:16px;right:auto;display:inline-flex;padding:8px 14px}.i{display:block}.t{display:inline}'];
        yield 'hidden' => ['hidden', '.f{display:none}'];
    }

    #[DataProvider('reopenVariants')]
    public function testReopenVariantsPerDevice(string $variant, string $rule): void
    {
        [$desktop, $mobile] = self::split(BannerStylesheet::compile(ConsentThemeV2::fromStored(['reopen' => ['desktop' => ['variant' => $variant], 'mobile' => ['variant' => $variant]]])));
        self::assertStringContainsString($rule, $desktop);
        self::assertStringContainsString($rule, $mobile);
    }

    public function testReopenDefaultsToTextOnDesktopAndIconOnPhones(): void
    {
        [$desktop, $mobile] = self::split(BannerStylesheet::compile(ConsentThemeV2::defaults()));
        self::assertStringContainsString('.i{display:none}.t{display:inline}', $desktop);
        self::assertStringContainsString('.i{display:block}.t{display:none}', $mobile);
    }

    public function testIconPathsComeFromTheAllowlist(): void
    {
        foreach (ConsentThemeV2::REOPEN_ICONS as $icon) {
            $path = BannerStylesheet::ICON_PATHS[$icon];
            self::assertMatchesRegularExpression('/^[MmLlHhVvCcSsQqTtAaZz0-9 .,-]+$/', $path, $icon . ' is plain path data');
            self::assertSame($path, BannerStylesheet::iconPath(ConsentThemeV2::fromStored(['reopen' => ['icon' => $icon]])));
        }
    }

    public function testBackdropOnlyForCentredLayouts(): void
    {
        $withBackdrop = ['colors' => ['backdrop' => '#00000080']];
        self::assertStringNotContainsString('100vmax', BannerStylesheet::compile(ConsentThemeV2::fromStored($withBackdrop)));
        [$desktop, $mobile] = self::split(BannerStylesheet::compile(ConsentThemeV2::fromStored($withBackdrop + ['layout' => ['desktop' => ['position' => 'center'], 'mobile' => ['position' => 'bottom']]])));
        self::assertStringContainsString('0 0 0 100vmax #00000080', $desktop);
        self::assertStringNotContainsString('100vmax', $mobile, 'the phone layout drops it again');
    }

    public function testInvalidCustomCssIsLeftOutRatherThanServed(): void
    {
        $theme = ConsentThemeV2::fromStored(['css' => '.button:first-child { display: none }']);
        self::assertSame(BannerStylesheet::compile(ConsentThemeV2::defaults()), BannerStylesheet::compile($theme));
        $valid = ConsentThemeV2::fromStored(['css' => '.button { font-weight: 700 }']);
        self::assertStringEndsWith('}.k{font-weight:700}', BannerStylesheet::compile($valid), 'custom rules come last');
    }

    /** @return array{string, string} desktop rules, the phone @media block */
    private static function split(string $css): array
    {
        $start = strpos($css, '@media (max-width:');
        self::assertIsInt($start);
        $end = strpos($css, '@keyframes', $start);
        self::assertIsInt($end);

        return [substr($css, 0, $start), substr($css, $start, $end - $start)];
    }
}
