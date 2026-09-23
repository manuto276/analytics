<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

use Analytics\Consent\Domain\BannerPlacement;
use Analytics\Consent\Domain\ConsentThemeV2;
use Analytics\Consent\Domain\ReopenPlacement;

/**
 * Compiles a v2 theme into the banner's whole stylesheet (`consent.css` in window.__an_cfg).
 *
 * The tracker builds the DOM with fixed short class names and no inline styles; everything visual
 * is here: `.b` dialog, `h2` title, `p` body, `a` policy link, `.a` actions row, `.k` Accept and
 * Reject (one rule for both — Garante 2021, B2), `.x` close, `.f` reopen button with `.i` icon and
 * `.t` label. Desktop rules come first, then one `@media (max-width: breakpoint)` block for phones,
 * then the operator's custom CSS ({@see CustomCssCompiler}).
 *
 * Every value written here comes from a validated theme field (hex colours, integers, enums, a
 * checked font stack), so the output cannot contain anything but these rules.
 */
final class BannerStylesheet
{
    /**
     * Reopen icons: SVG path data for a 24×24 viewBox, drawn with a 2px round stroke in
     * currentColor. Fixed allowlist; the tracker renders `<svg><path d></svg>` from `consent.ri`.
     */
    public const array ICON_PATHS = [
        'cookie' => 'M21 12a9 9 0 1 1-9-9 3 3 0 0 0 4 4 3 3 0 0 0 5 5zM8.5 8.5h.01M15.5 15h.01M9 15h.01M12 11.5h.01',
        'shield' => 'M12 3l7 3v5c0 4.5-3 8.5-7 10-4-1.5-7-5.5-7-10V6zM9 12l2 2 4-4',
        'fingerprint' => 'M4.5 16.5c.6-1.7.8-3 .8-4.5a6.7 6.7 0 0 1 13.4 0c0 2.2-.2 4.3-.8 6.3M8.3 19.5c.8-1.9 1.2-4.2 1.2-7.5a2.5 2.5 0 0 1 5 0c0 3.2-.4 6-1.3 8.6M12 12c0 3.4-.6 6.4-1.7 9M3.5 9A9.5 9.5 0 0 1 20.5 9',
        'settings' => 'M4 7h9M17 7h3M4 17h3M11 17h9M15 5v4M9 15v4',
    ];

    private const array SHADOWS = [
        'none' => 'none',
        'sm' => '0 1px 4px rgba(0,0,0,.2)',
        'md' => '0 4px 24px rgba(0,0,0,.25)',
        'lg' => '0 12px 48px rgba(0,0,0,.35)',
    ];

    /** Reopen button: [icon px, padding with a label, padding icon-only]. */
    private const array REOPEN_SIZES = [
        'sm' => [16, '6px 10px', '6px'],
        'md' => [20, '8px 14px', '10px'],
        'lg' => [24, '10px 18px', '12px'],
    ];

    public static function iconPath(ConsentThemeV2 $theme): string
    {
        return self::ICON_PATHS[$theme->reopenIcon] ?? self::ICON_PATHS['cookie'];
    }

    public static function compile(ConsentThemeV2 $t): string
    {
        $font = 'font-family:' . (ConsentThemeV2::fontStack($t->fontFamily) ?? 'inherit')
            . ';font-size:' . ($t->fontSize === null ? 'inherit' : $t->fontSize . 'px')
            . ';line-height:' . self::number($t->lineHeight);
        $shadow = self::SHADOWS[$t->shadow] ?? self::SHADOWS['md'];
        [$icon, $padText, $padIcon] = self::REOPEN_SIZES[$t->reopenSize] ?? self::REOPEN_SIZES['md'];

        $css = '.b,.f{position:fixed;z-index:2147483647;box-sizing:border-box;margin:0;' . $font . ';animation:i .2s}'
            . '.b{color:' . $t->text . ';background:' . $t->background . ';border:' . $t->borderWidth . 'px solid ' . $t->border
            . ';border-radius:' . $t->radius . 'px;box-shadow:' . $shadow . ';padding:' . $t->padding . 'px;overflow:auto;overscroll-behavior:contain}'
            . 'h2{margin:0 32px 8px 0;font-size:1.15em;line-height:1.3}'
            . 'p{margin:0 0 ' . max(8, $t->gap + 4) . 'px}'
            . 'a{color:' . $t->link . ';text-decoration:underline}'
            . '.a{display:flex;flex-wrap:wrap;gap:' . $t->gap . 'px}'
            // Accept and Reject: one rule for both (B2).
            . '.k{flex:1;margin:0;font:inherit;padding:8px 16px;cursor:pointer;border:2px solid ' . $t->accent . ';border-radius:' . $t->buttonRadius . 'px;background:' . $t->accent . ';color:' . $t->accentText . '}'
            . '.x{position:absolute;top:8px;right:8px;margin:0;font:inherit;font-size:1.4em;line-height:1;padding:4px 8px;border:0;background:none;color:inherit;cursor:pointer}'
            . '.f{display:inline-flex;align-items:center;gap:6px;padding:' . $padText . ';border:2px solid ' . $t->reopenBackgroundColor()
            . ';border-radius:' . $t->buttonRadius . 'px;background:' . $t->reopenBackgroundColor() . ';color:' . $t->reopenTextColor() . ';cursor:pointer;box-shadow:' . $shadow . '}'
            . '.i{width:' . $icon . 'px;height:' . $icon . 'px;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'
            . ':focus-visible{outline:2px solid ' . $t->accent . ';outline-offset:2px}'
            . self::placement($t->desktop, $t, true)
            . self::reopen($t->reopenDesktop, $padText, $padIcon)
            . '@media (max-width:' . $t->breakpoint . 'px){'
            . self::placement($t->mobile, $t, false)
            . self::reopen($t->reopenMobile, $padText, $padIcon)
            . '}'
            . '@keyframes i{from{opacity:0;transform:translateY(8px)}}'
            . '@media (prefers-reduced-motion:reduce){.b,.f{animation:none}}'
            . '@media (forced-colors:active){.b,.f,.k{border:1px solid CanvasText}}';

        if ($t->css !== '') {
            $custom = CustomCssCompiler::compile($t->css, $t);
            // A stored theme was validated when saved; if its CSS no longer passes (stricter rules),
            // the banner renders with the theme alone rather than with unvetted rules.
            if ($custom->ok()) {
                $css .= $custom->css;
            }
        }

        return $css;
    }

    /**
     * Position rules for the dialog. Every inset is written explicitly (no `auto` left to the
     * static position, which would depend on the host page's body margin) and corner layouts get
     * an explicit width, so the gaps on both sides are what the theme says.
     */
    private static function placement(BannerPlacement $p, ConsentThemeV2 $t, bool $desktop): string
    {
        $o = $p->offset;
        $available = 'calc(100% - ' . (2 * $o) . 'px)';
        $safeBottom = 'calc(' . $o . 'px + env(safe-area-inset-bottom,0px))';
        $safeTop = 'calc(' . $o . 'px + env(safe-area-inset-top,0px))';
        $max = $p->maxWidth ?? 576;
        $full = 'left:' . $o . 'px;right:' . $o . 'px;width:auto;max-width:none;margin:0';
        $centred = 'left:' . $o . 'px;right:' . $o . 'px;width:auto;max-width:' . $max . 'px;margin:0 auto';
        $rule = match ($desktop ? $p->position : 'm-' . $p->position) {
            'bottom' => 'top:auto;bottom:' . $o . 'px;' . $centred,
            'top' => 'top:' . $o . 'px;bottom:auto;' . $centred,
            'bottom-left' => 'top:auto;bottom:' . $o . 'px;left:' . $o . 'px;right:auto;width:min(' . $max . 'px,' . $available . ');max-width:none;margin:0',
            'bottom-right' => 'top:auto;bottom:' . $o . 'px;left:auto;right:' . $o . 'px;width:min(' . $max . 'px,' . $available . ');max-width:none;margin:0',
            'center' => 'top:50%;bottom:auto;translate:0 -50%;' . $centred,
            'm-top' => 'top:' . $safeTop . ';bottom:auto;translate:none;' . $full,
            'm-center' => 'top:50%;bottom:auto;translate:0 -50%;' . $full,
            'm-sheet' => 'top:auto;bottom:0;translate:none;left:0;right:0;width:auto;max-width:none;margin:0;border-radius:' . $t->radius . 'px ' . $t->radius . 'px 0 0;padding-bottom:calc(' . $t->padding . 'px + env(safe-area-inset-bottom,0px))',
            default => 'top:auto;bottom:' . $safeBottom . ';translate:none;' . $full,
        };
        $height = $p->position === 'sheet' ? '100%' : $available;
        $backdrop = $p->position === 'center' && $t->backdrop !== null
            ? ';box-shadow:' . (self::SHADOWS[$t->shadow] ?? self::SHADOWS['md']) . ',0 0 0 100vmax ' . $t->backdrop
            : ($desktop ? '' : ';box-shadow:' . (self::SHADOWS[$t->shadow] ?? self::SHADOWS['md']));

        return '.b{' . $rule . ';max-height:' . $height . $backdrop . '}'
            . ($p->buttons === 'stack' ? '.a{flex-direction:column}.k{flex:none}' : '.a{flex-direction:row}.k{flex:1}');
    }

    /** Reopen button position and variant (text, icon, icon-text, hidden) for one device class. */
    private static function reopen(ReopenPlacement $r, string $padText, string $padIcon): string
    {
        $o = $r->offset;
        $side = $r->position === 'bottom-right' ? 'left:auto;right:' . $o . 'px' : 'left:' . $o . 'px;right:auto';
        $position = '.f{top:auto;bottom:calc(' . $o . 'px + env(safe-area-inset-bottom,0px));' . $side;

        return match ($r->variant) {
            'hidden' => '.f{display:none}',
            'icon' => $position . ';display:inline-flex;padding:' . $padIcon . '}.i{display:block}.t{display:none}',
            'icon-text' => $position . ';display:inline-flex;padding:' . $padText . '}.i{display:block}.t{display:inline}',
            default => $position . ';display:inline-flex;padding:' . $padText . '}.i{display:none}.t{display:inline}',
        };
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.2F', $value), '0'), '.');
    }
}
