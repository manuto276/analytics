<?php

declare(strict_types=1);

namespace Analytics\Consent\Application;

use Analytics\Consent\Domain\ConsentThemeV2;

/**
 * Compiles the operator's custom CSS (`theme.css`) into rules for the banner's shadow root.
 *
 * The input is written with documented public class names (`.banner`, `.button`, …); the output
 * uses the tracker's short class names and is appended after the theme rules. Nothing is passed
 * through verbatim: selectors, properties and values are tokenised, checked against allowlists and
 * re-serialised, so the output cannot end the stylesheet, load a remote resource, hide or move the
 * banner, or style Accept and Reject differently:
 *
 * - selectors: one public class per compound, optionally with `:hover`, `:focus-visible`,
 *   `:active`, joined by descendant or `>` combinators. `.button` is both Accept and Reject and
 *   nothing can address only one of them — attribute selectors, `:first-child`, `:nth-*`, `:not()`,
 *   `:has()`, `+`, `~`, ids, elements and pseudo-elements are rejected (Garante 2021, B2);
 * - properties: {@see self::PROPERTIES}; `width`/`min-width`/`max-width` only on `.banner`, margins
 *   only on the parts inside it. `display`, `visibility`, `opacity`, `position`, insets,
 *   `transform`, `z-index`, `content`, `pointer-events`, `clip*`, `filter` and everything else is
 *   rejected;
 * - values: no `url()`, `expression()`, `var()`, `calc()`, escapes, `@import`, `@font-face`;
 *   lengths are range-checked; text colours must be opaque and keep a 4.5:1 contrast with the
 *   background they are drawn on (per rule, against the theme colours) (B6).
 *
 * Errors carry the 1-based line and column of the offending selector or declaration.
 */
final class CustomCssCompiler
{
    /** Public class name => the banner's own selector. */
    public const array PUBLIC_NAMES = [
        'banner' => '.b',
        'title' => 'h2',
        'body' => 'p',
        'link' => 'a',
        'actions' => '.a',
        'button' => '.k',
        'close' => '.x',
        'reopen' => '.f',
        'reopen-icon' => '.i',
    ];

    public const array PSEUDO_CLASSES = ['hover', 'focus-visible', 'active'];

    public const array PROPERTIES = [
        // colours and backgrounds (no url(): gradients are the only images)
        'color', 'background', 'background-color', 'background-image',
        // borders and corners
        'border', 'border-color', 'border-style', 'border-width',
        'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-right-radius', 'border-bottom-left-radius',
        // shadows
        'box-shadow', 'text-shadow',
        // type
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'font-stretch',
        'text-align', 'text-decoration', 'text-decoration-color', 'text-decoration-line', 'text-decoration-style',
        'text-decoration-thickness', 'text-underline-offset', 'text-transform', 'text-wrap',
        'letter-spacing', 'line-height',
        // spacing
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'gap', 'row-gap', 'column-gap',
        // size (.banner only)
        'width', 'max-width', 'min-width',
        // motion and focus
        'transition', 'transition-property', 'transition-duration', 'transition-timing-function', 'transition-delay',
        'outline', 'outline-color', 'outline-style', 'outline-width', 'outline-offset',
    ];

    /** Properties refused with a reason, because they are the obvious ways to defeat the banner. */
    private const array REFUSED = [
        'display' => 'it could hide the banner or one of its parts',
        'visibility' => 'it could hide the banner or one of its parts',
        'opacity' => 'it could hide the banner or one of its parts',
        'position' => 'it could move the banner off-screen',
        'top' => 'it could move the banner off-screen', 'right' => 'it could move the banner off-screen',
        'bottom' => 'it could move the banner off-screen', 'left' => 'it could move the banner off-screen',
        'inset' => 'it could move the banner off-screen',
        'transform' => 'it could move or hide the banner', 'translate' => 'it could move or hide the banner',
        'scale' => 'it could move or hide the banner', 'rotate' => 'it could move or hide the banner',
        'z-index' => 'it could put the banner under the page',
        'content' => 'it could inject text into the banner',
        'pointer-events' => 'it could make the buttons unclickable',
        'clip' => 'it could hide the banner or one of its parts', 'clip-path' => 'it could hide the banner or one of its parts',
        'filter' => 'it could hide the banner or one of its parts',
        'order' => 'it could reorder Accept and Reject',
        'flex-direction' => 'it could reorder Accept and Reject',
    ];

    private const array SIZE_PROPERTIES = ['width', 'max-width', 'min-width'];
    private const array MARGIN_PROPERTIES = ['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left'];
    /** Fixed-positioned boxes: margins would move them. */
    private const array FIXED_PARTS = ['banner', 'reopen'];
    private const array NEGATIVE_ALLOWED = ['letter-spacing', 'outline-offset', 'text-underline-offset', 'box-shadow', 'text-shadow'];
    private const array COLOR_PROPERTIES = ['color', 'background-color'];
    private const array FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla', 'linear-gradient', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'cubic-bezier', 'steps'];
    private const array UNITS = ['px', 'em', 'rem', '%', 's', 'ms', 'deg', 'turn'];
    private const array COLOR_KEYWORDS = ['inherit', 'currentcolor', 'transparent', 'white', 'black'];

    /** @var list<array{type: string, value: string, line: int, column: int}> */
    private array $tokens = [];
    private int $pos = 0;
    /** @var list<CssError> */
    private array $errors = [];
    /**
     * Background the custom CSS gave `.banner` so far in the current block (top level, or the
     * `@media` block being read, which starts from the top-level one): the parts inside the
     * banner are measured against it.
     */
    private ?string $bannerBackground = null;

    private function __construct(private readonly ConsentThemeV2 $theme) {}

    public static function compile(string $css, ConsentThemeV2 $theme): CompiledCss
    {
        $compiler = new self($theme);
        if (\strlen($css) > ConsentThemeV2::CSS_MAX_BYTES) {
            return new CompiledCss('', [new CssError(1, 1, \sprintf('Custom CSS must be at most %d bytes.', ConsentThemeV2::CSS_MAX_BYTES))]);
        }
        if (!$compiler->tokenize($css)) {
            return new CompiledCss('', $compiler->errors);
        }
        $out = $compiler->block(false);

        return new CompiledCss($compiler->errors === [] ? $out : '', $compiler->errors);
    }

    /**
     * Split the source into text runs and the structural characters `{`, `}`, `;`. Comments are
     * dropped; strings stay inside text runs so that a `;` or `}` in a string does not split.
     */
    private function tokenize(string $css): bool
    {
        $line = 1;
        $column = 1;
        $n = \strlen($css);
        $text = '';
        /** @var array{int, int}|null $start */
        $start = null;

        for ($i = 0; $i < $n; ++$i) {
            $char = $css[$i];
            if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
                $end = strpos($css, '*/', $i + 2);
                if ($end === false) {
                    $this->errors[] = new CssError($line, $column, 'Unterminated comment.');

                    return false;
                }
                for ($j = $i; $j < $end + 2; ++$j) {
                    [$line, $column] = self::advance($css[$j], $line, $column);
                }
                $i = $end + 1;
                $text .= ' ';
                continue;
            }
            $problem = self::characterProblem($char);
            if ($problem !== null) {
                $this->errors[] = new CssError($line, $column, $problem);

                return false;
            }
            if ($char === '"' || $char === "'") {
                $end = $i + 1;
                while ($end < $n && $css[$end] !== $char && $css[$end] !== "\n") {
                    ++$end;
                }
                if ($end >= $n || $css[$end] !== $char) {
                    $this->errors[] = new CssError($line, $column, 'Unterminated string.');

                    return false;
                }
                $literal = substr($css, $i, $end - $i + 1);
                if (preg_match('/[^\x20-\x7E]|\\\\/', $literal) === 1) {
                    $this->errors[] = new CssError($line, $column, 'Only printable ASCII without escapes is allowed in strings.');

                    return false;
                }
                $start ??= [$line, $column];
                $text .= $literal;
                $column += \strlen($literal);
                $i = $end;
                continue;
            }
            if (\in_array($char, ['{', '}', ';'], true)) {
                $this->pushText($text, $start);
                $text = '';
                $start = null;
                $this->tokens[] = ['type' => $char, 'value' => $char, 'line' => $line, 'column' => $column];
                ++$column;
                continue;
            }
            if ($start === null && !ctype_space($char)) {
                $start = [$line, $column];
            }
            $text .= $char;
            [$line, $column] = self::advance($char, $line, $column);
        }
        $this->pushText($text, $start);

        return true;
    }

    /** @param array{int, int}|null $start */
    private function pushText(string $text, ?array $start): void
    {
        if ($start !== null && trim($text) !== '') {
            $this->tokens[] = ['type' => 'text', 'value' => $text, 'line' => $start[0], 'column' => $start[1]];
        }
    }

    /** @return array{int, int} */
    private static function advance(string $char, int $line, int $column): array
    {
        return $char === "\n" ? [$line + 1, 1] : [$line, $column + 1];
    }

    private static function characterProblem(string $char): ?string
    {
        $ord = \ord($char);

        return match (true) {
            $char === '\\' => 'Backslash escapes are not allowed.',
            $char === '<' => 'The character "<" is not allowed.',
            $ord >= 0x80 => 'Only ASCII is allowed outside comments.',
            ($ord < 0x20 && !\in_array($char, ["\n", "\r", "\t"], true)) || $ord === 0x7F => 'Control characters are not allowed.',
            default => null,
        };
    }

    /** Rules at the top level, or inside an `@media` block. */
    private function block(bool $inMedia): string
    {
        $out = '';
        while ($this->pos < \count($this->tokens)) {
            $token = $this->tokens[$this->pos];
            if ($token['type'] === '}') {
                if ($inMedia) {
                    return $out;
                }
                $this->error($token, 'Unexpected "}".');
                ++$this->pos;
                continue;
            }
            if ($token['type'] === ';') {
                ++$this->pos;
                continue;
            }
            if ($token['type'] === '{') {
                $this->error($token, 'Missing selector before "{".');
                $this->skipBlock();
                continue;
            }
            $prelude = trim($token['value']);
            ++$this->pos;
            $next = $this->tokens[$this->pos] ?? null;
            if (str_starts_with($prelude, '@')) {
                $out .= $this->atRule($token, $prelude, $next, $inMedia);
                continue;
            }
            if ($next === null || $next['type'] !== '{') {
                $this->error($token, \sprintf('Expected "{" after "%s".', self::excerpt($prelude)));
                if ($next !== null && $next['type'] === ';') {
                    ++$this->pos;
                }
                continue;
            }
            ++$this->pos;
            $out .= $this->rule($token, $prelude);
        }
        if ($inMedia) {
            $last = $this->tokens[\count($this->tokens) - 1];
            $this->error($last, 'Unclosed "@media" block.');
        }

        return $out;
    }

    /**
     * @param array{type: string, value: string, line: int, column: int}      $token
     * @param array{type: string, value: string, line: int, column: int}|null $next
     */
    private function atRule(array $token, string $prelude, ?array $next, bool $inMedia): string
    {
        preg_match('/^@([A-Za-z-]*)/', $prelude, $m);
        $name = strtolower($m[1] ?? '');
        $hasBlock = $next !== null && $next['type'] === '{';
        if ($name !== 'media' || $inMedia) {
            $this->error($token, $name === 'media'
                ? 'Nested "@media" blocks are not allowed.'
                : \sprintf('"@%s" is not allowed: only "@media" blocks are.', $name));
            if ($hasBlock) {
                $this->skipBlock();
            } elseif ($next !== null && $next['type'] === ';') {
                ++$this->pos;
            }

            return '';
        }
        if (!$hasBlock) {
            $this->error($token, 'Expected "{" after the "@media" query.');

            return '';
        }
        ++$this->pos;
        $query = $this->mediaQuery(trim(substr($prelude, 6)));
        if ($query === null) {
            $this->error($token, \sprintf('Unsupported media query "%s": use (max-width: Npx), (min-width: Npx), (prefers-color-scheme: dark|light), (prefers-reduced-motion: reduce|no-preference), (hover: hover|none) or (pointer: fine|coarse), joined by "and".', self::excerpt(trim(substr($prelude, 6)))));
        }
        $outer = $this->bannerBackground;
        $inner = $this->block(true);
        $this->bannerBackground = $outer;
        if (($this->tokens[$this->pos]['type'] ?? null) === '}') {
            ++$this->pos;
        }

        return $query === null || $inner === '' ? '' : '@media ' . $query . '{' . $inner . '}';
    }

    private function mediaQuery(string $query): ?string
    {
        $query = strtolower((string) preg_replace('/\s+/', ' ', $query));
        $query = (string) preg_replace('/^(only )?screen and /', '', $query);
        if ($query === '') {
            return null;
        }
        $out = [];
        foreach (explode(' and ', $query) as $part) {
            $part = trim($part);
            if (preg_match('/^\(\s*(max-width|min-width)\s*:\s*(\d{2,4})px\s*\)$/', $part, $m) === 1) {
                $out[] = '(' . $m[1] . ':' . $m[2] . 'px)';
            } elseif (preg_match('/^\(\s*(prefers-color-scheme)\s*:\s*(dark|light)\s*\)$|^\(\s*(prefers-reduced-motion)\s*:\s*(reduce|no-preference)\s*\)$|^\(\s*(hover)\s*:\s*(hover|none)\s*\)$|^\(\s*(pointer)\s*:\s*(fine|coarse)\s*\)$/', $part, $m) === 1) {
                $parts = array_values(array_filter(\array_slice($m, 1), static fn(string $s): bool => $s !== ''));
                $out[] = '(' . $parts[0] . ':' . $parts[1] . ')';
            } else {
                return null;
            }
        }

        return implode(' and ', $out);
    }

    /** @param array{type: string, value: string, line: int, column: int} $token */
    private function rule(array $token, string $prelude): string
    {
        $selector = $this->selectorList($token, $prelude);
        /** @var list<array{string, string}> $declarations */
        $declarations = [];
        $color = null;
        $background = null;
        $closed = false;
        while ($this->pos < \count($this->tokens)) {
            $decl = $this->tokens[$this->pos];
            if ($decl['type'] === '}') {
                ++$this->pos;
                $closed = true;
                break;
            }
            if ($decl['type'] === '{') {
                $this->error($decl, 'Nested rules are not allowed.');
                $this->skipBlock();
                continue;
            }
            ++$this->pos;
            if ($decl['type'] === ';') {
                continue;
            }
            if (($this->tokens[$this->pos]['type'] ?? null) === '{') {
                $this->error($decl, 'Nested rules are not allowed.');
                $this->skipBlock();
                continue;
            }
            if ($selector === null) {
                continue;
            }
            $parsed = $this->declaration($decl, $selector['subjects']);
            if ($parsed === null) {
                continue;
            }
            $declarations[] = [$parsed['property'], $parsed['value']];
            if ($parsed['property'] === 'color') {
                $color = $parsed['color'];
            } elseif (\in_array($parsed['property'], ['background', 'background-color'], true)) {
                $background = $parsed['color'];
            }
        }
        if (!$closed) {
            $this->error($token, 'Unclosed rule: missing "}".');
        }
        if ($selector === null || $declarations === []) {
            return '';
        }
        $this->contrast($token, $selector['subjects'], $color, $background);
        if ($background !== null && \in_array('banner', $selector['subjects'], true)) {
            $this->bannerBackground = $background;
        }

        return $selector['css'] . '{' . implode(';', array_map(static fn(array $d): string => $d[0] . ':' . $d[1], $declarations)) . '}';
    }

    /**
     * @param array{type: string, value: string, line: int, column: int} $token
     *
     * @return array{css: string, subjects: list<string>}|null
     */
    private function selectorList(array $token, string $prelude): ?array
    {
        $css = [];
        $subjects = [];
        foreach (explode(',', $prelude) as $complex) {
            $complex = trim($complex);
            $problem = $this->selectorProblem($complex);
            if ($problem !== null) {
                $this->error($token, \sprintf('Selector "%s": %s', self::excerpt($complex), $problem));

                return null;
            }
            $parts = self::splitComplex($complex);
            $out = '';
            $subject = '';
            foreach ($parts as $i => $part) {
                if ($i % 2 === 1) {
                    $out .= trim($part) === '>' ? '>' : ' ';
                    continue;
                }
                if (preg_match('/^\.([a-z-]+)((?::[a-z-]+)*)$/', $part, $m) !== 1 || !isset(self::PUBLIC_NAMES[$m[1]])) {
                    return null;
                }
                $subject = $m[1];
                $out .= self::PUBLIC_NAMES[$m[1]] . $m[2];
            }
            $css[] = $out;
            $subjects[] = $subject;
        }

        return ['css' => implode(',', $css), 'subjects' => array_values(array_unique($subjects))];
    }

    private function selectorProblem(string $complex): ?string
    {
        if ($complex === '') {
            return 'empty selector.';
        }
        $names = '.' . implode(', .', array_keys(self::PUBLIC_NAMES));
        if (preg_match('/[\[\]]/', $complex) === 1) {
            return 'attribute selectors are not allowed (they could single out Accept or Reject).';
        }
        if (preg_match('/[+~]/', $complex) === 1) {
            return 'the "+" and "~" combinators are not allowed (they could single out Accept or Reject).';
        }
        if (str_contains($complex, '::')) {
            return 'pseudo-elements are not allowed.';
        }
        if (str_contains($complex, '#')) {
            return 'id selectors are not allowed; use the public class names ' . $names . '.';
        }
        if (str_contains($complex, '*')) {
            return 'the universal selector is not allowed; use the public class names ' . $names . '.';
        }
        if (preg_match_all('/:([A-Za-z-]+)(\()?/', $complex, $pseudos, \PREG_SET_ORDER) > 0) {
            foreach ($pseudos as $pseudo) {
                $name = strtolower($pseudo[1]);
                if (!\in_array($name, self::PSEUDO_CLASSES, true) || isset($pseudo[2])) {
                    return \sprintf('the pseudo-class ":%s" is not allowed (only :hover, :focus-visible and :active, which apply to Accept and Reject alike).', $name . (isset($pseudo[2]) ? '()' : ''));
                }
            }
        }
        $parts = self::splitComplex($complex);
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            if ($part === '') {
                return 'a combinator needs a class on both sides.';
            }
            if (preg_match('/^\.([a-z-]+)((?::(?:hover|focus-visible|active))*)$/', $part, $m) !== 1) {
                if (preg_match('/^\.[a-z-]+\./', $part) === 1) {
                    return 'use one class per compound selector.';
                }

                return \sprintf('"%s" is not one of the public class names %s.', $part, $names);
            }
            if (!\array_key_exists($m[1], self::PUBLIC_NAMES)) {
                return \sprintf('unknown class ".%s"; use one of %s.', $m[1], $names);
            }
        }

        return null;
    }

    /**
     * @param array{type: string, value: string, line: int, column: int} $token
     * @param list<string>                                               $subjects
     *
     * @return array{property: string, value: string, color: string|null}|null
     */
    private function declaration(array $token, array $subjects): ?array
    {
        $text = trim($token['value']);
        $colon = strpos($text, ':');
        if ($colon === false) {
            $this->error($token, \sprintf('Expected "property: value", got "%s".', self::excerpt($text)));

            return null;
        }
        $property = strtolower(trim(substr($text, 0, $colon)));
        $value = trim(substr($text, $colon + 1));
        if (preg_match('/^-?[a-z][a-z-]*$/', $property) !== 1) {
            $this->error($token, \sprintf('Invalid property name "%s".', self::excerpt($property)));

            return null;
        }
        if (isset(self::REFUSED[$property])) {
            $this->error($token, \sprintf('The property "%s" is not allowed: %s.', $property, self::REFUSED[$property]));

            return null;
        }
        if (!\in_array($property, self::PROPERTIES, true)) {
            $this->error($token, \sprintf('The property "%s" is not allowed. Allowed: colours, backgrounds without url(), borders, radius, shadows, font-*, text-*, letter-spacing, line-height, padding, margin (inside the banner), gap, width/max-width (.banner), transition, outline.', $property));

            return null;
        }
        if (\in_array($property, self::SIZE_PROPERTIES, true) && $subjects !== ['banner']) {
            $this->error($token, \sprintf('"%s" is only allowed on .banner.', $property));

            return null;
        }
        if (\in_array($property, self::MARGIN_PROPERTIES, true) && array_intersect($subjects, self::FIXED_PARTS) !== []) {
            $this->error($token, \sprintf('"%s" is not allowed on .banner or .reopen: it would move them; use it on the parts inside the banner.', $property));

            return null;
        }
        $important = false;
        if (preg_match('/^(.*?)\s*!\s*important$/is', $value, $m) === 1) {
            $value = $m[1];
            $important = true;
        }
        if ($value === '') {
            $this->error($token, \sprintf('Missing value for "%s".', $property));

            return null;
        }
        $normalised = $this->value($token, $property, $value);
        if ($normalised === null) {
            return null;
        }
        $color = null;
        if (\in_array($property, self::COLOR_PROPERTIES, true) || $property === 'background') {
            $color = self::parseColor($normalised);
            if ($property === 'color' && $color === null && (strtolower($normalised) === 'transparent' || preg_match('/^#([0-9a-f]{4}|[0-9a-f]{8})$|^(rgba|hsla)\(|\//i', $normalised) === 1)) {
                $this->error($token, 'Text colours must be opaque: the contrast with the background must stay at least 4.5:1.');

                return null;
            }
            if (\in_array($property, self::COLOR_PROPERTIES, true) && $color === null && !\in_array(strtolower($normalised), self::COLOR_KEYWORDS, true)) {
                $this->error($token, \sprintf('"%s" must be a #hex, rgb() or hsl() colour, or one of: %s.', $property, implode(', ', self::COLOR_KEYWORDS)));

                return null;
            }
        }

        return ['property' => $property, 'value' => $normalised . ($important ? '!important' : ''), 'color' => $color];
    }

    /**
     * Tokenise and check one value, returning it re-serialised (single spaces between tokens).
     *
     * @param array{type: string, value: string, line: int, column: int} $token
     */
    private function value(array $token, string $property, string $value): ?string
    {
        $pattern = '/\G(?:(?<ws>\s+)|(?<str>"[^"\n]*"|\'[^\'\n]*\')|(?<hash>#[0-9a-zA-Z]+)|(?<num>[+-]?(?:\d+\.?\d*|\.\d+))(?<unit>[a-zA-Z]+|%)?|(?<fn>-?[a-zA-Z][a-zA-Z0-9-]*)\(|(?<ident>-?[a-zA-Z][a-zA-Z0-9-]*)|(?<close>\))|(?<punct>[,\/]))/';
        $offset = 0;
        $depth = 0;
        $out = [];
        $space = false;
        $length = \strlen($value);
        while ($offset < $length) {
            if (preg_match($pattern, $value, $m, \PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                $this->error($token, \sprintf('Unexpected "%s" in the value of "%s".', $value[$offset], $property));

                return null;
            }
            $offset += \strlen($m[0]);
            if ($m['ws'] !== null) {
                $space = $out !== [];
                continue;
            }
            $piece = $m[0];
            if ($m['str'] !== null) {
                if ($property !== 'font-family') {
                    $this->error($token, 'Strings are only allowed in font-family.');

                    return null;
                }
            } elseif ($m['hash'] !== null) {
                if (preg_match('/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $piece) !== 1) {
                    $this->error($token, \sprintf('Invalid colour "%s".', $piece));

                    return null;
                }
                $piece = strtolower($piece);
            } elseif ($m['num'] !== null) {
                $unit = strtolower($m['unit'] ?? '');
                if ($unit !== '' && !\in_array($unit, self::UNITS, true)) {
                    $this->error($token, \sprintf('The unit "%s" is not allowed (use px, em, rem, %%, s, ms, deg).', $unit));

                    return null;
                }
                if ($depth === 0) {
                    $problem = self::numberProblem($property, (float) $m['num'], $unit);
                    if ($problem !== null) {
                        $this->error($token, \sprintf('"%s: %s": %s', $property, self::excerpt($value), $problem));

                        return null;
                    }
                }
                $piece = $m['num'] . $unit;
            } elseif ($m['fn'] !== null) {
                $name = strtolower($m['fn']);
                if (!\in_array($name, self::FUNCTIONS, true)) {
                    $this->error($token, match (true) {
                        $name === 'url' => 'url() is not allowed: the banner never loads remote resources.',
                        str_contains($name, 'expression') => 'expression() is not allowed.',
                        default => \sprintf('The function "%s()" is not allowed (allowed: %s).', $name, implode(', ', array_map(static fn(string $f): string => $f . '()', self::FUNCTIONS))),
                    });

                    return null;
                }
                ++$depth;
                $piece = $name . '(';
            } elseif ($m['ident'] !== null) {
                $ident = strtolower($m['ident']);
                if (str_contains($ident, 'javascript') || str_contains($ident, 'expression')) {
                    $this->error($token, \sprintf('"%s" is not allowed in a value.', $m['ident']));

                    return null;
                }
                $piece = $ident === 'currentcolor' ? 'currentColor' : $m['ident'];
            } elseif ($m['close'] !== null) {
                if ($depth === 0) {
                    $this->error($token, 'Unbalanced ")".');

                    return null;
                }
                --$depth;
            }
            $previous = $out === [] ? '' : $out[\count($out) - 1];
            $glue = $space && $previous !== '' && !str_ends_with($previous, '(') && !str_ends_with($previous, ',') && $piece !== ')' && $piece !== ',' ? ' ' : '';
            $out[] = $glue . $piece;
            $space = false;
        }
        if ($depth !== 0) {
            $this->error($token, 'Unbalanced "(".');

            return null;
        }

        return implode('', $out);
    }

    /** Range check for a number outside functions; null when acceptable. */
    private static function numberProblem(string $property, float $number, string $unit): ?string
    {
        $px = match ($unit) {
            'px' => $number,
            'em', 'rem' => $number * 16,
            default => null,
        };
        $between = static fn(float $v, float $min, float $max): bool => $v >= $min && $v <= $max;
        if (\in_array($unit, ['s', 'ms'], true)) {
            return $between($unit === 's' ? $number * 1000 : $number, 0, 2000) ? null : 'durations must be between 0 and 2s.';
        }
        if (\in_array($unit, ['deg', 'turn'], true)) {
            return null;
        }
        if (\in_array($property, self::SIZE_PROPERTIES, true)) {
            if ($unit === '%') {
                return $between($number, 50, 100) ? null : 'use 50%–100%.';
            }

            return $px !== null && $between($px, 200, 1200) ? null : 'use 200px–1200px (12.5em–75em) or 50%–100%.';
        }
        if ($property === 'font-size') {
            if ($unit === '%') {
                return $between($number, 62.5, 250) ? null : 'use 62.5%–250%.';
            }

            return $px !== null && $between($px, 10, 40) ? null : 'use 10px–40px (0.625em–2.5em).';
        }
        if ($property === 'line-height') {
            return match (true) {
                $unit === '' => $between($number, 0.8, 3) ? null : 'use a number between 0.8 and 3.',
                $unit === '%' => $between($number, 80, 300) ? null : 'use 80%–300%.',
                default => $px !== null && $between($px, 8, 64) ? null : 'use 8px–64px.',
            };
        }
        if (str_starts_with($property, 'border') && str_ends_with($property, 'radius')) {
            if ($unit === '%') {
                return $between($number, 0, 50) ? null : 'use 0%–50%.';
            }

            return $px === null || $between($px, 0, 999) ? null : 'use 0px–999px.';
        }
        if ($unit === '%') {
            return 'percentages are only allowed for width, font-size, line-height and border-radius.';
        }
        if ($px === null) {
            return null;
        }
        if ($property === 'letter-spacing') {
            return $between($px, -2, 8) ? null : 'use -0.125em–0.5em (-2px–8px).';
        }
        if ($px < 0 && !\in_array($property, self::NEGATIVE_ALLOWED, true)) {
            return 'negative lengths are not allowed here.';
        }

        return $between(abs($px), 0, 64) ? null : 'lengths must be at most 64px (4em).';
    }

    /**
     * @param array{type: string, value: string, line: int, column: int} $token
     * @param list<string>                                               $subjects
     */
    private function contrast(array $token, array $subjects, ?string $color, ?string $background): void
    {
        if ($color === null && $background === null) {
            return;
        }
        $t = $this->theme;
        $surface = $this->bannerBackground ?? $t->background;
        foreach ($subjects as $subject) {
            $pairs = match ($subject) {
                'button' => [[$t->accentText, $t->accent]],
                'reopen', 'reopen-icon' => [[$t->reopenTextColor(), $t->reopenBackgroundColor()]],
                'link' => [[$t->link, $surface]],
                'banner' => [[$t->text, $surface], [$t->link, $surface]],
                default => [[$t->text, $surface]],
            };
            foreach ($color === null ? $pairs : [[$color, $pairs[0][1]]] as [$fg, $bg]) {
                $ratio = ContrastChecker::ratio($fg, $background ?? $bg);
                if ($ratio < ContrastChecker::MINIMUM) {
                    $this->error($token, \sprintf('.%s: text/background contrast is %.2f:1, it must be at least %.1f:1.', $subject, $ratio, ContrastChecker::MINIMUM));

                    return;
                }
            }
        }
    }

    /** #rrggbb for a single opaque colour value (#hex, rgb(), hsl(), white, black), else null. */
    public static function parseColor(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === 'white' || $value === 'black') {
            return $value === 'white' ? '#ffffff' : '#000000';
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m) === 1) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $value) === 1) {
            return $value;
        }
        if (preg_match('/^rgba?\((\d{1,3})[, ]+(\d{1,3})[, ]+(\d{1,3})(?:\s*[,\/]\s*(1|1\.0+|100%))?\)$/', $value, $m) === 1) {
            $channels = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (max($channels) > 255) {
                return null;
            }

            return \sprintf('#%02x%02x%02x', ...$channels);
        }
        if (preg_match('/^hsla?\((\d{1,3}(?:\.\d+)?)(?:deg)?[, ]+(\d{1,3}(?:\.\d+)?)%[, ]+(\d{1,3}(?:\.\d+)?)%(?:\s*[,\/]\s*(1|1\.0+|100%))?\)$/', $value, $m) === 1) {
            [$h, $s, $l] = [fmod((float) $m[1], 360) / 360, min(100.0, (float) $m[2]) / 100, min(100.0, (float) $m[3]) / 100];
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $channel = static function (float $t) use ($p, $q): int {
                $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
                $v = match (true) {
                    $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                    $t < 1 / 2 => $q,
                    $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                    default => $p,
                };

                return (int) round($v * 255);
            };

            return \sprintf('#%02x%02x%02x', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
        }

        return null;
    }

    private function skipBlock(): void
    {
        $depth = 0;
        while ($this->pos < \count($this->tokens)) {
            $type = $this->tokens[$this->pos]['type'];
            ++$this->pos;
            if ($type === '{') {
                ++$depth;
            } elseif ($type === '}') {
                --$depth;
                if ($depth <= 0) {
                    return;
                }
            }
        }
    }

    /** @param array{type: string, value: string, line: int, column: int} $token */
    private function error(array $token, string $message): void
    {
        $this->errors[] = new CssError($token['line'], $token['column'], $message);
    }

    /**
     * Compounds at even indexes, combinators at odd ones.
     *
     * @return list<string>
     */
    private static function splitComplex(string $complex): array
    {
        $parts = preg_split('/(\s*>\s*|\s+)/', $complex, -1, \PREG_SPLIT_DELIM_CAPTURE);

        return $parts === false ? [] : $parts;
    }

    private static function excerpt(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($text));

        return \strlen($text) > 60 ? substr($text, 0, 57) . '...' : $text;
    }
}
