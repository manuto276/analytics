<?php

declare(strict_types=1);

namespace Analytics\Consent\Domain;

/**
 * Theme v2 of the consent banner (docs/api/consent-theme.v2.schema.json).
 *
 * `consent_configs.theme` is JSON and still holds v1 themes (`bg, fg, ac, acf, rad, pos`) written
 * before v2 existed: they are never migrated, but upgraded whenever they are read
 * ({@see self::fromStored()} → {@see self::fromV1()}), so a published v1 banner keeps its colours,
 * radius and desktop position and gains the responsive mobile layout.
 *
 * Accept and Reject share one button style ({@see self::$accent}/{@see self::$accentText}); there is
 * deliberately no "secondary" colour (Garante 2021, B2).
 */
final readonly class ConsentThemeV2
{
    public const array V1_KEYS = ['bg', 'fg', 'ac', 'acf', 'rad', 'pos'];
    public const array V2_KEYS = ['colors', 'font', 'shape', 'spacing', 'border', 'shadow', 'layout', 'reopen', 'css'];

    public const array DESKTOP_POSITIONS = ['bottom', 'bottom-left', 'bottom-right', 'top', 'center'];
    public const array MOBILE_POSITIONS = ['bottom', 'top', 'center', 'sheet'];
    public const array BUTTON_LAYOUTS = ['row', 'stack'];
    public const array SHADOWS = ['none', 'sm', 'md', 'lg'];
    public const array REOPEN_ICONS = ['cookie', 'shield', 'fingerprint', 'settings'];
    public const array REOPEN_SIZES = ['sm', 'md', 'lg'];
    public const array REOPEN_VARIANTS = ['text', 'icon', 'icon-text', 'hidden'];
    public const array REOPEN_POSITIONS = ['bottom-left', 'bottom-right'];
    public const array COLOR_KEYS = ['background', 'text', 'accent', 'accentText', 'border', 'link'];

    /** Inclusive integer ranges, by JSON path inside the theme. */
    public const array RANGES = [
        'font.size' => [12, 20],
        'shape.radius' => [0, 32],
        'shape.buttonRadius' => [0, 999],
        'spacing.padding' => [8, 48],
        'spacing.gap' => [0, 32],
        'border.width' => [0, 4],
        'layout.breakpoint' => [480, 1024],
        'layout.desktop.maxWidth' => [280, 1200],
        'layout.desktop.offset' => [0, 64],
        'layout.mobile.offset' => [0, 32],
        'reopen.desktop.offset' => [0, 64],
        'reopen.mobile.offset' => [0, 64],
    ];
    public const float LINE_HEIGHT_MIN = 1.0;
    public const float LINE_HEIGHT_MAX = 2.0;
    public const int CSS_MAX_BYTES = 8192;
    public const int FONT_FAMILY_MAX = 200;

    public const string COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';
    public const string BACKDROP_PATTERN = '/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/';

    /** Generic families and system keywords that are written unquoted. */
    private const array GENERIC_FAMILIES = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong', '-apple-system', 'blinkmacsystemfont'];
    private const string SYSTEM_STACK = 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';

    public function __construct(
        public string $background = '#ffffff',
        public string $text = '#111827',
        public string $accent = '#1d4ed8',
        public string $accentText = '#ffffff',
        public string $border = '#e5e7eb',
        public string $link = '#1d4ed8',
        public ?string $backdrop = null,
        public string $fontFamily = 'inherit',
        /** px; null inherits the page's font size. */
        public ?int $fontSize = null,
        public float $lineHeight = 1.5,
        public int $radius = 8,
        public int $buttonRadius = 8,
        public int $padding = 16,
        public int $gap = 8,
        public int $borderWidth = 0,
        public string $shadow = 'md',
        public int $breakpoint = 640,
        public BannerPlacement $desktop = new BannerPlacement('bottom', 16, 'row', 576),
        public BannerPlacement $mobile = new BannerPlacement('bottom', 16, 'row'),
        public string $reopenIcon = 'cookie',
        public string $reopenSize = 'md',
        /** null: the accent colour. */
        public ?string $reopenBackground = null,
        /** null: the text-on-accent colour. */
        public ?string $reopenText = null,
        public ReopenPlacement $reopenDesktop = new ReopenPlacement('text', 'bottom-left', 16),
        public ReopenPlacement $reopenMobile = new ReopenPlacement('icon', 'bottom-left', 16),
        /** Custom CSS as written by the operator (public class names); compiled on read. */
        public string $css = '',
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * True for a theme written in the v1 shape (any v1 key and no v2 key).
     *
     * @param array<array-key, mixed> $theme
     */
    public static function isV1(array $theme): bool
    {
        return array_intersect(array_map('strval', array_keys($theme)), self::V1_KEYS) !== []
            && array_intersect(array_map('strval', array_keys($theme)), self::V2_KEYS) === [];
    }

    /**
     * Upgrade a v1 theme. What v1 rendered is kept: colours, one radius for the box and the
     * buttons, the desktop position (the floating button follows `bottom-right`), inherited font,
     * a text-coloured link, the medium shadow, the text reopen button. What v1 lacked — a mobile
     * layout — gets the v2 default: full width with equal 16px gaps.
     *
     * @param array<array-key, mixed> $v1
     */
    public static function fromV1(array $v1): self
    {
        $d = self::defaults();
        $color = static fn(string $key, string $default): string => self::color($v1[$key] ?? null) ?? $default;
        $background = $color('bg', $d->background);
        $text = $color('fg', $d->text);
        $rad = \is_int($v1['rad'] ?? null) ? max(0, min(32, $v1['rad'])) : $d->radius;
        $pos = \is_string($v1['pos'] ?? null) && \in_array($v1['pos'], ['bottom', 'bottom-left', 'bottom-right'], true) ? $v1['pos'] : 'bottom';
        $reopenSide = $pos === 'bottom-right' ? 'bottom-right' : 'bottom-left';

        return new self(
            background: $background,
            text: $text,
            accent: $color('ac', $d->accent),
            accentText: $color('acf', $d->accentText),
            border: $d->border,
            link: $text,
            radius: $rad,
            buttonRadius: $rad,
            desktop: new BannerPlacement($pos, 16, 'row', 576),
            mobile: new BannerPlacement('bottom', 16, 'row'),
            reopenDesktop: new ReopenPlacement('text', $reopenSide, 16),
            reopenMobile: new ReopenPlacement('text', $reopenSide, 16),
        );
    }

    /**
     * The theme as stored (v1 or v2, possibly partial), upgraded to a complete v2 theme. Values the
     * current rules do not accept fall back to the default instead of failing: a stored theme was
     * valid when it was saved and must keep rendering.
     *
     * @param array<array-key, mixed> $theme
     */
    public static function fromStored(array $theme): self
    {
        if (self::isV1($theme)) {
            return self::fromV1($theme);
        }
        $d = self::defaults();
        $colors = self::map($theme, 'colors');
        $font = self::map($theme, 'font');
        $shape = self::map($theme, 'shape');
        $spacing = self::map($theme, 'spacing');
        $layout = self::map($theme, 'layout');
        $desktop = self::map($layout, 'desktop');
        $mobile = self::map($layout, 'mobile');
        $reopen = self::map($theme, 'reopen');
        $reopenDesktop = self::map($reopen, 'desktop');
        $reopenMobile = self::map($reopen, 'mobile');
        $int = static fn(array $from, string $key, string $range, int $default): int => \is_int($from[$key] ?? null) && $from[$key] >= self::RANGES[$range][0] && $from[$key] <= self::RANGES[$range][1] ? $from[$key] : $default;
        $choice = static fn(array $from, string $key, array $allowed, string $default): string => \is_string($from[$key] ?? null) && \in_array($from[$key], $allowed, true) ? $from[$key] : $default;
        $lineHeight = $font['lineHeight'] ?? null;
        $backdrop = $colors['backdrop'] ?? null;
        $family = $font['family'] ?? null;
        $css = $theme['css'] ?? null;
        $fontSize = $font['size'] ?? null;

        return new self(
            background: self::color($colors['background'] ?? null) ?? $d->background,
            text: self::color($colors['text'] ?? null) ?? $d->text,
            accent: self::color($colors['accent'] ?? null) ?? $d->accent,
            accentText: self::color($colors['accentText'] ?? null) ?? $d->accentText,
            border: self::color($colors['border'] ?? null) ?? $d->border,
            link: self::color($colors['link'] ?? null) ?? $d->link,
            backdrop: \is_string($backdrop) && preg_match(self::BACKDROP_PATTERN, $backdrop) === 1 ? strtolower($backdrop) : null,
            fontFamily: \is_string($family) && self::fontStack($family) !== null ? $family : $d->fontFamily,
            fontSize: \is_int($fontSize) && $fontSize >= self::RANGES['font.size'][0] && $fontSize <= self::RANGES['font.size'][1] ? $fontSize : null,
            lineHeight: (\is_int($lineHeight) || \is_float($lineHeight)) && $lineHeight >= self::LINE_HEIGHT_MIN && $lineHeight <= self::LINE_HEIGHT_MAX ? (float) $lineHeight : $d->lineHeight,
            radius: $int($shape, 'radius', 'shape.radius', $d->radius),
            buttonRadius: $int($shape, 'buttonRadius', 'shape.buttonRadius', $d->buttonRadius),
            padding: $int($spacing, 'padding', 'spacing.padding', $d->padding),
            gap: $int($spacing, 'gap', 'spacing.gap', $d->gap),
            borderWidth: $int(self::map($theme, 'border'), 'width', 'border.width', $d->borderWidth),
            shadow: $choice($theme, 'shadow', self::SHADOWS, $d->shadow),
            breakpoint: $int($layout, 'breakpoint', 'layout.breakpoint', $d->breakpoint),
            desktop: new BannerPlacement(
                $choice($desktop, 'position', self::DESKTOP_POSITIONS, $d->desktop->position),
                $int($desktop, 'offset', 'layout.desktop.offset', $d->desktop->offset),
                $choice($desktop, 'buttons', self::BUTTON_LAYOUTS, $d->desktop->buttons),
                $int($desktop, 'maxWidth', 'layout.desktop.maxWidth', $d->desktop->maxWidth ?? 576),
            ),
            mobile: new BannerPlacement(
                $choice($mobile, 'position', self::MOBILE_POSITIONS, $d->mobile->position),
                $int($mobile, 'offset', 'layout.mobile.offset', $d->mobile->offset),
                $choice($mobile, 'buttons', self::BUTTON_LAYOUTS, $d->mobile->buttons),
            ),
            reopenIcon: $choice($reopen, 'icon', self::REOPEN_ICONS, $d->reopenIcon),
            reopenSize: $choice($reopen, 'size', self::REOPEN_SIZES, $d->reopenSize),
            reopenBackground: self::color($reopen['background'] ?? null),
            reopenText: self::color($reopen['text'] ?? null),
            reopenDesktop: new ReopenPlacement(
                $choice($reopenDesktop, 'variant', self::REOPEN_VARIANTS, $d->reopenDesktop->variant),
                $choice($reopenDesktop, 'position', self::REOPEN_POSITIONS, $d->reopenDesktop->position),
                $int($reopenDesktop, 'offset', 'reopen.desktop.offset', $d->reopenDesktop->offset),
            ),
            reopenMobile: new ReopenPlacement(
                $choice($reopenMobile, 'variant', self::REOPEN_VARIANTS, $d->reopenMobile->variant),
                $choice($reopenMobile, 'position', self::REOPEN_POSITIONS, $d->reopenMobile->position),
                $int($reopenMobile, 'offset', 'reopen.mobile.offset', $d->reopenMobile->offset),
            ),
            css: \is_string($css) && \strlen($css) <= self::CSS_MAX_BYTES ? $css : '',
        );
    }

    /** Reopen button background: its own colour or the accent. */
    public function reopenBackgroundColor(): string
    {
        return $this->reopenBackground ?? $this->accent;
    }

    /** Reopen button text/icon colour: its own colour or the text-on-accent. */
    public function reopenTextColor(): string
    {
        return $this->reopenText ?? $this->accentText;
    }

    /**
     * The v2 JSON shape (docs/api/consent-theme.v2.schema.json), complete.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'colors' => [
                'background' => $this->background,
                'text' => $this->text,
                'accent' => $this->accent,
                'accentText' => $this->accentText,
                'border' => $this->border,
                'link' => $this->link,
                'backdrop' => $this->backdrop,
            ],
            'font' => ['family' => $this->fontFamily, 'size' => $this->fontSize, 'lineHeight' => $this->lineHeight],
            'shape' => ['radius' => $this->radius, 'buttonRadius' => $this->buttonRadius],
            'spacing' => ['padding' => $this->padding, 'gap' => $this->gap],
            'border' => ['width' => $this->borderWidth],
            'shadow' => $this->shadow,
            'layout' => [
                'breakpoint' => $this->breakpoint,
                'desktop' => $this->desktop->toArray(),
                'mobile' => $this->mobile->toArray(),
            ],
            'reopen' => [
                'icon' => $this->reopenIcon,
                'size' => $this->reopenSize,
                'background' => $this->reopenBackground,
                'text' => $this->reopenText,
                'desktop' => $this->reopenDesktop->toArray(),
                'mobile' => $this->reopenMobile->toArray(),
            ],
            'css' => $this->css,
        ];
    }

    /**
     * The CSS `font-family` value for `font.family`, or null when it is not acceptable.
     * Accepted: `inherit`, `system` (a system-font stack), or a comma-separated list of family names
     * — letters, digits, spaces, `-` and `_`, optionally quoted. No URLs, no escapes, no other
     * punctuation: the value is written into the stylesheet and must not be able to end the rule.
     */
    public static function fontStack(string $family): ?string
    {
        $family = trim($family);
        if ($family === 'inherit') {
            return 'inherit';
        }
        if ($family === 'system') {
            return self::SYSTEM_STACK;
        }
        if ($family === '' || \strlen($family) > self::FONT_FAMILY_MAX) {
            return null;
        }
        $out = [];
        foreach (explode(',', $family) as $name) {
            $name = trim($name);
            if (preg_match('/^(?:"([A-Za-z0-9 _-]+)"|\'([A-Za-z0-9 _-]+)\'|(-?[A-Za-z][A-Za-z0-9 _-]*))$/', $name, $m, \PREG_UNMATCHED_AS_NULL) !== 1) {
                return null;
            }
            $plain = trim($m[1] ?? $m[2] ?? $m[3] ?? '');
            if ($plain === '') {
                return null;
            }
            $generic = $m[3] !== null && \in_array(strtolower($plain), self::GENERIC_FAMILIES, true);
            $out[] = $generic ? strtolower($plain) : '"' . $plain . '"';
        }

        return implode(',', $out);
    }

    private static function color(mixed $value): ?string
    {
        return \is_string($value) && preg_match(self::COLOR_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * @param array<array-key, mixed> $from
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $from, string $key): array
    {
        $value = $from[$key] ?? null;

        return \is_array($value) ? $value : [];
    }
}
