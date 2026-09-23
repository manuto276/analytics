<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

use Analytics\Consent\Domain\BannerPlacement;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Consent\Domain\ReopenPlacement;
use Analytics\Shared\Validation\Input;

/**
 * Validates the `theme` of a consent draft. Both shapes are accepted on write:
 *
 * - v1 (`bg, fg, ac, acf, rad, pos`), as written by clients that predate v2 — validated with the v1
 *   rules and stored unchanged (it is upgraded on read by {@see ConsentThemeV2::fromStored()});
 * - v2 (docs/api/consent-theme.v2.schema.json), possibly partial — every field range-checked,
 *   missing fields filled with the defaults, stored complete.
 *
 * Contrast (≥ 4.5:1, {@see ContrastChecker}) is enforced for text/background, text-on-accent/accent,
 * link/background and the reopen button's text/background; custom CSS goes through
 * {@see CustomCssCompiler} and its errors are reported under `theme.css` with line and column.
 */
final class ThemeValidator
{
    /**
     * @return array<string, mixed> the theme to store
     */
    public static function validate(Input $theme): array
    {
        $raw = $theme->raw();
        $keys = array_map('strval', array_keys($raw));
        $v1 = array_intersect($keys, ConsentThemeV2::V1_KEYS);
        $v2 = array_intersect($keys, ConsentThemeV2::V2_KEYS);
        if ($v1 !== [] && $v2 !== []) {
            $theme->error(array_values($v1)[0], 'Use either the v1 keys (bg, fg, ac, acf, rad, pos) or the v2 keys (colors, font, shape, spacing, border, shadow, layout, reopen, css), not both.');

            return [];
        }
        foreach (array_diff($keys, ConsentThemeV2::V1_KEYS, ConsentThemeV2::V2_KEYS) as $unknown) {
            $theme->error($unknown, 'Unknown theme field.');
        }

        return $v1 !== [] ? self::v1($theme) : self::v2($theme)->toArray();
    }

    /** @return array{bg: string, fg: string, ac: string, acf: string, rad: int, pos: string} */
    private static function v1(Input $input): array
    {
        $theme = [
            'bg' => $input->string('bg', 7, 7, ConsentThemeV2::COLOR_PATTERN),
            'fg' => $input->string('fg', 7, 7, ConsentThemeV2::COLOR_PATTERN),
            'ac' => $input->string('ac', 7, 7, ConsentThemeV2::COLOR_PATTERN),
            'acf' => $input->string('acf', 7, 7, ConsentThemeV2::COLOR_PATTERN),
            'rad' => $input->int('rad', 8, 0, 24),
            'pos' => $input->choice('pos', ['bottom', 'bottom-left', 'bottom-right'], 'bottom'),
        ];
        if ($input->errors() === []) {
            self::contrast($input, 'fg', $theme['fg'], $theme['bg'], 'Text/background');
            self::contrast($input, 'acf', $theme['acf'], $theme['ac'], 'Button text/button');
        }

        return $theme;
    }

    private static function v2(Input $input): ConsentThemeV2
    {
        $d = ConsentThemeV2::defaults();
        $colors = self::section($input, 'colors');
        $color = static fn(string $key, string $default): string => strtolower($colors->optionalString($key, 7, 7, ConsentThemeV2::COLOR_PATTERN) ?? $default);
        $background = $color('background', $d->background);
        $text = $color('text', $d->text);
        $accent = $color('accent', $d->accent);
        $accentText = $color('accentText', $d->accentText);
        $border = $color('border', $d->border);
        $link = $color('link', $d->link);
        $backdrop = $colors->optionalString('backdrop', 9, 7, ConsentThemeV2::BACKDROP_PATTERN);

        $font = self::section($input, 'font');
        $family = $font->optionalString('family', ConsentThemeV2::FONT_FAMILY_MAX) ?? $d->fontFamily;
        if (ConsentThemeV2::fontStack($family) === null) {
            $font->error('family', 'Use "inherit", "system" or a comma-separated list of font family names (letters, digits, spaces, "-" and "_", optionally quoted). Fonts are never downloaded.');
        }
        $size = $font->optionalInt('size', ...ConsentThemeV2::RANGES['font.size']);
        $lineHeight = $d->lineHeight;
        $rawLineHeight = $font->raw()['lineHeight'] ?? null;
        if ($rawLineHeight !== null) {
            if ((!\is_int($rawLineHeight) && !\is_float($rawLineHeight)) || $rawLineHeight < ConsentThemeV2::LINE_HEIGHT_MIN || $rawLineHeight > ConsentThemeV2::LINE_HEIGHT_MAX) {
                $font->error('lineHeight', \sprintf('Must be a number between %.1f and %.1f.', ConsentThemeV2::LINE_HEIGHT_MIN, ConsentThemeV2::LINE_HEIGHT_MAX));
            } else {
                $lineHeight = round((float) $rawLineHeight, 2);
            }
        }

        $shape = self::section($input, 'shape');
        $spacing = self::section($input, 'spacing');
        $borderInput = self::section($input, 'border');
        $layout = self::section($input, 'layout');
        $desktop = self::section($layout, 'desktop');
        $mobile = self::section($layout, 'mobile');
        $reopen = self::section($input, 'reopen');
        $reopenDesktop = self::section($reopen, 'desktop');
        $reopenMobile = self::section($reopen, 'mobile');
        $int = static fn(Input $in, string $key, string $range, int $default): int => $in->optionalInt($key, ...ConsentThemeV2::RANGES[$range]) ?? $default;

        $theme = new ConsentThemeV2(
            background: $background,
            text: $text,
            accent: $accent,
            accentText: $accentText,
            border: $border,
            link: $link,
            backdrop: $backdrop === null ? null : strtolower($backdrop),
            fontFamily: $family,
            fontSize: $size,
            lineHeight: $lineHeight,
            radius: $int($shape, 'radius', 'shape.radius', $d->radius),
            buttonRadius: $int($shape, 'buttonRadius', 'shape.buttonRadius', $d->buttonRadius),
            padding: $int($spacing, 'padding', 'spacing.padding', $d->padding),
            gap: $int($spacing, 'gap', 'spacing.gap', $d->gap),
            borderWidth: $int($borderInput, 'width', 'border.width', $d->borderWidth),
            shadow: $input->choice('shadow', ConsentThemeV2::SHADOWS, $d->shadow),
            breakpoint: $int($layout, 'breakpoint', 'layout.breakpoint', $d->breakpoint),
            desktop: new BannerPlacement(
                $desktop->choice('position', ConsentThemeV2::DESKTOP_POSITIONS, $d->desktop->position),
                $int($desktop, 'offset', 'layout.desktop.offset', $d->desktop->offset),
                $desktop->choice('buttons', ConsentThemeV2::BUTTON_LAYOUTS, $d->desktop->buttons),
                $int($desktop, 'maxWidth', 'layout.desktop.maxWidth', $d->desktop->maxWidth ?? 576),
            ),
            mobile: new BannerPlacement(
                $mobile->choice('position', ConsentThemeV2::MOBILE_POSITIONS, $d->mobile->position),
                $int($mobile, 'offset', 'layout.mobile.offset', $d->mobile->offset),
                $mobile->choice('buttons', ConsentThemeV2::BUTTON_LAYOUTS, $d->mobile->buttons),
            ),
            reopenIcon: $reopen->choice('icon', ConsentThemeV2::REOPEN_ICONS, $d->reopenIcon),
            reopenSize: $reopen->choice('size', ConsentThemeV2::REOPEN_SIZES, $d->reopenSize),
            reopenBackground: self::lower($reopen->optionalString('background', 7, 7, ConsentThemeV2::COLOR_PATTERN)),
            reopenText: self::lower($reopen->optionalString('text', 7, 7, ConsentThemeV2::COLOR_PATTERN)),
            reopenDesktop: new ReopenPlacement(
                $reopenDesktop->choice('variant', ConsentThemeV2::REOPEN_VARIANTS, $d->reopenDesktop->variant),
                $reopenDesktop->choice('position', ConsentThemeV2::REOPEN_POSITIONS, $d->reopenDesktop->position),
                $int($reopenDesktop, 'offset', 'reopen.desktop.offset', $d->reopenDesktop->offset),
            ),
            reopenMobile: new ReopenPlacement(
                $reopenMobile->choice('variant', ConsentThemeV2::REOPEN_VARIANTS, $d->reopenMobile->variant),
                $reopenMobile->choice('position', ConsentThemeV2::REOPEN_POSITIONS, $d->reopenMobile->position),
                $int($reopenMobile, 'offset', 'reopen.mobile.offset', $d->reopenMobile->offset),
            ),
            css: self::css($input),
        );
        foreach ([$colors, $font, $shape, $spacing, $borderInput, $layout, $desktop, $mobile, $reopen, $reopenDesktop, $reopenMobile] as $section) {
            $input->merge($section);
        }
        if ($input->errors() !== []) {
            return $theme;
        }

        $colorsInput = new Input([], $colors->prefix());
        self::contrast($colorsInput, 'text', $theme->text, $theme->background, 'Text/background');
        self::contrast($colorsInput, 'accentText', $theme->accentText, $theme->accent, 'Button text/button');
        self::contrast($colorsInput, 'link', $theme->link, $theme->background, 'Link/background');
        $input->merge($colorsInput);
        $reopenInput = new Input([], $reopen->prefix());
        self::contrast($reopenInput, 'text', $theme->reopenTextColor(), $theme->reopenBackgroundColor(), 'Reopen button text/background');
        $input->merge($reopenInput);

        if ($theme->css !== '' && $input->errors() === []) {
            foreach (CustomCssCompiler::compile($theme->css, $theme)->errors as $error) {
                $input->error('css', (string) $error);
            }
        }

        return $theme;
    }

    /** The custom CSS, untrimmed so that line and column numbers match the editor. */
    private static function css(Input $input): string
    {
        $css = $input->raw()['css'] ?? null;
        if ($css === null) {
            return '';
        }
        if (!\is_string($css)) {
            $input->error('css', 'Must be a string.');

            return '';
        }
        if (\strlen($css) > ConsentThemeV2::CSS_MAX_BYTES) {
            $input->error('css', \sprintf('Must be at most %d bytes.', ConsentThemeV2::CSS_MAX_BYTES));

            return '';
        }

        return trim($css) === '' ? '' : $css;
    }

    private static function section(Input $parent, string $key): Input
    {
        return $parent->nested($key) ?? new Input([], $parent->prefix() . $key . '.');
    }

    private static function contrast(Input $input, string $key, string $foreground, string $background, string $label): void
    {
        if (ContrastChecker::ratio($foreground, $background) < ContrastChecker::MINIMUM) {
            $input->error($key, \sprintf('%s contrast must be at least %.1f:1.', $label, ContrastChecker::MINIMUM));
        }
    }

    private static function lower(?string $value): ?string
    {
        return $value === null ? null : strtolower($value);
    }
}
