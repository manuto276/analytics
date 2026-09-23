<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Consent;

use Analytics\Consent\Application\CompiledCss;
use Analytics\Consent\Application\CustomCssCompiler;
use Analytics\Consent\Domain\ConsentThemeV2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The custom CSS guardrails. Every hostile input must be refused with a precise position, and
 * nothing the compiler accepts may style Accept differently from Reject (Garante 2021, B2).
 */
final class CustomCssCompilerTest extends TestCase
{
    private static function compile(string $css, ?ConsentThemeV2 $theme = null): CompiledCss
    {
        return CustomCssCompiler::compile($css, $theme ?? ConsentThemeV2::defaults());
    }

    public function testMapsPublicNamesAndReserialises(): void
    {
        $result = self::compile(<<<'CSS'
            /* our brand */
            .banner { max-width: 480px; border-radius: 16px }
            .title{font-size:1.25em;letter-spacing:-0.01em}
            .body , .link { line-height: 1.6 }
            .actions   >   .button:hover:focus-visible { background-color: #0B3A8F !important; }
            .reopen .reopen-icon { width: 20px }
            CSS);
        // ".reopen .reopen-icon { width }" is refused (width is for .banner), so compile the rest.
        self::assertFalse($result->ok());

        $result = self::compile(<<<'CSS'
            /* our brand */
            .banner { max-width: 480px; border-radius: 16px }
            .title{font-size:1.25em;letter-spacing:-0.01em}
            .body , .link { line-height: 1.6 }
            .actions   >   .button:hover:focus-visible { background-color: #0B3A8F !important; }
            .close:active { color: #111827 }
            .reopen { font-family: "Inter", sans-serif; box-shadow: 0 2px 8px rgba(0, 0, 0, .3) }
            .reopen .reopen-icon { color: #ffffff }
            CSS);
        self::assertSame([], array_map('strval', $result->errors));
        self::assertTrue($result->ok());
        self::assertSame(
            '.b{max-width:480px;border-radius:16px}'
            . 'h2{font-size:1.25em;letter-spacing:-0.01em}'
            . 'p,a{line-height:1.6}'
            . '.a>.k:hover:focus-visible{background-color:#0b3a8f!important}'
            . '.x:active{color:#111827}'
            . '.f{font-family:"Inter",sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.3)}'
            . '.f .i{color:#ffffff}',
            $result->css,
        );
    }

    public function testMediaBlocks(): void
    {
        $result = self::compile("@media screen and (max-width: 480px) and (prefers-color-scheme: dark) {\n  .banner { background: #111827; color: #f9fafb }\n  .link { color: #93c5fd }\n}\n@media (prefers-reduced-motion: reduce) { .button { transition: none } }");
        self::assertSame([], array_map('strval', $result->errors));
        self::assertSame('@media (max-width:480px) and (prefers-color-scheme:dark){.b{background:#111827;color:#f9fafb}a{color:#93c5fd}}@media (prefers-reduced-motion:reduce){.k{transition:none}}', $result->css);
    }

    public function testEmptyInputCompilesToNothing(): void
    {
        self::assertSame('', self::compile('')->css);
        self::assertTrue(self::compile("  /* nothing */\n")->ok());
        self::assertSame('', self::compile('.button {}')->css, 'an empty rule is dropped');
    }

    /**
     * Selectors that would single out Accept or Reject, or reach outside the public names.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hostileSelectors(): iterable
    {
        yield 'attribute: data-a' => ['.button[data-a] { color: red }', 'attribute selectors are not allowed'];
        yield 'attribute: data-r' => ['.actions [data-r] { font-size: 10px }', 'attribute selectors are not allowed'];
        yield ':first-child' => ['.button:first-child { font-weight: 900 }', '":first-child" is not allowed'];
        yield ':last-child' => ['.button:last-child { font-weight: 900 }', '":last-child" is not allowed'];
        yield ':nth-child' => ['.button:nth-child(2) { font-weight: 900 }', '":nth-child()" is not allowed'];
        yield ':nth-of-type' => ['.button:nth-of-type(1) { font-weight: 900 }', '":nth-of-type()" is not allowed'];
        yield ':first-of-type' => ['.button:first-of-type{padding:20px}', '":first-of-type" is not allowed'];
        yield ':only-child' => ['.button:only-child{padding:20px}', '":only-child" is not allowed'];
        yield ':not()' => ['.button:not(.x) { font-size: 12px }', '":not()" is not allowed'];
        yield ':has()' => ['.actions:has(.button) { gap: 0 }', '":has()" is not allowed'];
        yield ':is()' => [':is(.button) { gap: 0 }', '":is()" is not allowed'];
        yield ':where()' => ['.banner :where(.button) { gap: 0 }', '":where()" is not allowed'];
        yield 'adjacent +' => ['.button + .button { font-size: 30px }', '"+" and "~" combinators are not allowed'];
        yield 'sibling ~' => ['.button ~ .button { font-size: 30px }', '"+" and "~" combinators are not allowed'];
        yield 'element' => ['button { color: red }', 'is not one of the public class names'];
        yield 'short class' => ['.k { color: red }', 'unknown class ".k"'];
        yield 'id' => ['#t { color: red }', 'id selectors are not allowed'];
        yield 'universal' => ['* { color: red }', 'universal selector is not allowed'];
        yield 'pseudo-element' => ['.button::after { color: red }', 'pseudo-elements are not allowed'];
        yield ':host' => [':host { color: red }', '":host" is not allowed'];
        yield 'two classes' => ['.button.close { color: red }', 'one class per compound'];
        yield 'empty in list' => ['.button, { color: red }', 'empty selector'];
        yield 'dangling combinator' => ['.actions > { color: red }', 'a combinator needs a class on both sides'];
        yield 'comment splits the name' => ['.but/**/ton { color: red }', 'unknown class ".but"'];
        yield 'comment hides a pseudo' => ['.button/* x */:first-child { color: red }', '":first-child" is not allowed'];
        yield 'focus-within' => ['.actions:focus-within { gap: 0 }', '":focus-within" is not allowed'];
    }

    #[DataProvider('hostileSelectors')]
    public function testHostileSelectorsAreRefused(string $css, string $message): void
    {
        $result = self::compile($css);
        self::assertFalse($result->ok(), $css);
        self::assertSame('', $result->css, 'nothing is emitted when there are errors');
        self::assertStringContainsString($message, (string) $result->errors[0], $css);
        self::assertSame(1, $result->errors[0]->line);
    }

    /** @return iterable<string, array{string, string}> */
    public static function hostileDeclarations(): iterable
    {
        yield 'display:none' => ['.button { display: none }', '"display" is not allowed'];
        yield 'visibility' => ['.banner { visibility: hidden }', '"visibility" is not allowed'];
        yield 'opacity' => ['.banner { opacity: 0 }', '"opacity" is not allowed'];
        yield 'position' => ['.banner { position: absolute }', '"position" is not allowed'];
        yield 'top' => ['.banner { top: -9999px }', '"top" is not allowed'];
        yield 'left' => ['.reopen { left: 0 }', '"left" is not allowed'];
        yield 'inset' => ['.banner { inset: 0 }', '"inset" is not allowed'];
        yield 'transform' => ['.banner { transform: translateX(-200%) }', '"transform" is not allowed'];
        yield 'translate' => ['.banner { translate: 0 100vh }', '"translate" is not allowed'];
        yield 'z-index' => ['.banner { z-index: -1 }', '"z-index" is not allowed'];
        yield 'content' => ['.title { content: "hi" }', '"content" is not allowed'];
        yield 'pointer-events' => ['.button { pointer-events: none }', '"pointer-events" is not allowed'];
        yield 'clip-path' => ['.button { clip-path: inset(50%) }', '"clip-path" is not allowed'];
        yield 'clip' => ['.button { clip: rect(0 0 0 0) }', '"clip" is not allowed'];
        yield 'filter' => ['.banner { filter: opacity(0) }', '"filter" is not allowed'];
        yield 'order' => ['.button { order: 2 }', '"order" is not allowed'];
        yield 'flex-direction' => ['.actions { flex-direction: row-reverse }', '"flex-direction" is not allowed'];
        yield 'height' => ['.banner { height: 1px }', '"height" is not allowed'];
        yield 'vendor property' => ['.banner { -webkit-transform: none }', '"-webkit-transform" is not allowed'];
        yield 'url()' => ['.banner { background: url(https://evil.test/x.png) }', 'url() is not allowed'];
        yield 'url() in image' => ['.button { background-image: url("//evil.test/p.gif") }', 'url() is not allowed'];
        yield 'image-set()' => ['.banner { background-image: image-set("x.png" 1x) }', '"image-set()" is not allowed'];
        yield 'expression()' => ['.banner { color: expression(alert(1)) }', 'expression() is not allowed'];
        yield 'javascript:' => ['.banner { background: javascript:alert(1) }', '"javascript" is not allowed'];
        yield 'colon in value' => ['.banner { background: x:y }', 'Unexpected ":"'];
        yield 'var()' => ['.banner { color: var(--x) }', '"var()" is not allowed'];
        yield 'calc()' => ['.banner { max-width: calc(100% + 9999px) }', '"calc()" is not allowed'];
        yield 'env()' => ['.banner { padding: env(safe-area-inset-top) }', '"env()" is not allowed'];
        yield 'attr()' => ['.title { font-family: attr(title) }', '"attr()" is not allowed'];
        yield 'width on a button' => ['.button { width: 400px }', '"width" is only allowed on .banner'];
        yield 'max-width on actions' => ['.banner .actions { max-width: 300px }', '"max-width" is only allowed on .banner'];
        yield 'tiny banner' => ['.banner { width: 10px }', 'use 200px–1200px'];
        yield 'viewport unit' => ['.banner { width: 100vw }', 'The unit "vw" is not allowed'];
        yield 'margin on the banner' => ['.banner { margin-left: 20px }', 'not allowed on .banner or .reopen'];
        yield 'margin on reopen' => ['.reopen { margin: 0 }', 'not allowed on .banner or .reopen'];
        yield 'negative margin' => ['.button { margin-left: -500px }', 'negative lengths are not allowed'];
        yield 'huge padding' => ['.button { padding: 0 9000px }', 'at most 64px'];
        yield 'text-indent' => ['.button { text-indent: -9999px }', '"text-indent" is not allowed'];
        yield 'zero font size' => ['.button { font-size: 0 }', 'use 10px–40px'];
        yield 'huge font size' => ['.body { font-size: 400px }', 'use 10px–40px'];
        yield 'huge letter spacing' => ['.button { letter-spacing: 3em }', 'use -0.125em–0.5em'];
        yield 'huge shadow spread' => ['.banner { box-shadow: 0 0 0 9999px #000 }', 'at most 64px'];
        yield 'long transition' => ['.button { transition: color 60s }', 'durations must be between 0 and 2s'];
        yield 'transparent text' => ['.button { color: transparent }', 'Text colours must be opaque'];
        yield 'alpha text' => ['.body { color: rgba(17, 24, 39, 0.1) }', 'Text colours must be opaque'];
        yield 'alpha hex text' => ['.body { color: #11182710 }', 'Text colours must be opaque'];
        yield 'named colour' => ['.body { color: rebeccapurple }', 'must be a #hex, rgb() or hsl() colour'];
        yield 'low contrast button' => ['.button { color: #2563eb }', '.button: text/background contrast is'];
        yield 'low contrast background' => ['.button { background-color: #f3f4f6 }', '.button: text/background contrast is'];
        yield 'invisible banner text' => ['.banner { background: #111827 }', '.banner: text/background contrast is'];
        yield 'low contrast via rgb()' => ['.title { color: rgb(240, 240, 240) }', '.title: text/background contrast is'];
        yield 'low contrast via hsl()' => ['.body { color: hsl(0, 0%, 95%) }', '.body: text/background contrast is'];
        yield 'invalid hex' => ['.banner { color: #12345 }', 'Invalid colour'];
        yield 'string outside font-family' => ['.banner { border-style: "solid" }', 'Strings are only allowed in font-family'];
        yield 'unbalanced paren' => ['.banner { background: rgb(0,0,0 }', 'Unbalanced "("'];
        yield 'missing colon' => ['.banner { color #000 }', 'Expected "property: value"'];
        yield 'missing value' => ['.banner { color: }', 'Missing value for "color"'];
        yield 'bang in value' => ['.banner { color: #000 ! important ! }', 'Unexpected "!"'];
    }

    #[DataProvider('hostileDeclarations')]
    public function testHostileDeclarationsAreRefused(string $css, string $message): void
    {
        $result = self::compile($css);
        self::assertFalse($result->ok(), $css);
        self::assertSame('', $result->css);
        self::assertStringContainsString($message, (string) $result->errors[0], $css);
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function structuralAttacks(): iterable
    {
        yield '@import' => ["@import url(https://evil.test/x.css);\n.banner{color:#000}", '"@import" is not allowed', 1, 1];
        yield '@font-face' => ["\n  @font-face { font-family: X; src: url(x.woff) }", '"@font-face" is not allowed', 2, 3];
        yield '@keyframes' => ['@keyframes spin { from { opacity: 0 } }', '"@keyframes" is not allowed', 1, 1];
        yield '@supports' => ['@supports (display:grid) { .banner { color: #000 } }', '"@supports" is not allowed', 1, 1];
        yield '@layer' => ['@layer x;', '"@layer" is not allowed', 1, 1];
        yield '@namespace' => ['@namespace svg url(http://www.w3.org/2000/svg);', '"@namespace" is not allowed', 1, 1];
        yield 'nested @media' => ["@media (max-width: 600px) {\n  @media (hover: hover) { .button { color: #fff } }\n}", 'Nested "@media" blocks are not allowed', 2, 3];
        yield 'import inside media' => ["@media (max-width: 600px) {\n @import url(x.css);\n}", '"@import" is not allowed', 2, 2];
        yield 'bad media query' => ['@media print { .banner { color: #000 } }', 'Unsupported media query "print"', 1, 1];
        yield 'media query injection' => ['@media (max-width: 600px), (min-width:0) { .banner { color: #000 } }', 'Unsupported media query', 1, 1];
        yield 'style end tag' => [".banner { color: #000 }\n</style><script>alert(1)</script>", 'The character "<" is not allowed', 2, 1];
        yield 'backslash escape' => [".banner { color: #000 }\n.b\\75 tton { color: red }", 'Backslash escapes are not allowed', 2, 3];
        yield 'escaped url' => ['.banner { background: u\\72l(x) }', 'Backslash escapes are not allowed', 1, 24];
        yield 'escape in string' => ['.title { font-family: "a\\"b" }', 'without escapes', 1, 23];
        yield 'unterminated comment' => [".banner { color: #000 }\n/* never closed", 'Unterminated comment', 2, 1];
        yield 'unterminated string' => ['.title { font-family: "Inter }', 'Unterminated string', 1, 23];
        yield 'closing brace breaks out' => [".banner { color: #000 } }\n.button { color: #fff }", 'Unexpected "}"', 1, 25];
        yield 'missing closing brace' => ['.banner { color: #000', 'Unclosed rule', 1, 1];
        yield 'nested rule' => ['.banner { .button { color: red } }', 'Nested rules are not allowed', 1, 11];
        yield 'rule without braces' => ['.banner color: red;', 'Expected "{" after', 1, 1];
        yield 'control character' => [".banner { color: #000\x00 }", 'Control characters are not allowed', 1, 22];
        yield 'non-ASCII lookalike' => ['.bаnner { color: #000 }', 'Only ASCII is allowed outside comments', 1, 3];
        yield 'error position on line 3' => ["/* a\n   b */ .banner { color: #000 }\n.button:first-child { color: #fff }", '":first-child" is not allowed', 3, 1];
        yield 'declaration position' => ["\n.banner {\n  color: #000;\n    display: none;\n}", '"display" is not allowed', 4, 5];
    }

    #[DataProvider('structuralAttacks')]
    public function testStructuralAttacksAreRefusedWithTheirPosition(string $css, string $message, int $line, int $column): void
    {
        $result = self::compile($css);
        self::assertFalse($result->ok(), $css);
        self::assertSame('', $result->css);
        $error = $result->errors[0];
        self::assertStringContainsString($message, $error->message, $css);
        self::assertSame([$line, $column], [$error->line, $error->column], (string) $error);
        self::assertStringStartsWith(\sprintf('Line %d, column %d: ', $line, $column), (string) $error);
    }

    public function testCollectsSeveralErrors(): void
    {
        $result = self::compile(".button:first-child { color: #fff }\n.banner { display: none; opacity: 0 }\n.link { color: #1d4ed8 }");
        self::assertCount(3, $result->errors);
        self::assertSame([1, 2, 2], array_map(static fn(\Analytics\Consent\Application\CssError $e): int => $e->line, $result->errors));
        self::assertSame([1, 11, 26], array_map(static fn(\Analytics\Consent\Application\CssError $e): int => $e->column, $result->errors));
    }

    public function testTooLong(): void
    {
        $result = self::compile(str_repeat(' ', ConsentThemeV2::CSS_MAX_BYTES + 1));
        self::assertFalse($result->ok());
        self::assertStringContainsString('at most 8192 bytes', (string) $result->errors[0]);
    }

    /**
     * Garante 2021, B2: whatever the compiler accepts, Accept and Reject get the same rules. Both
     * are `.k` siblings in `.a` that differ only in `data-a`/`data-r` and their order; accepted
     * output may therefore never mention an attribute, a structural pseudo-class or a sibling
     * combinator, and every selector reaching a button must reach both.
     */
    public function testNothingAcceptedCanTellAcceptFromReject(): void
    {
        $inputs = [
            '.button{font-weight:700}',
            '.actions .button:hover{background-color:#1e40af}',
            '.actions>.button:active{border-color:#1e3a8a}',
            '.banner .actions > .button:focus-visible{outline:3px solid #000}',
            '@media (max-width:480px){.button{padding:12px 20px}}',
        ];
        foreach ($inputs as $css) {
            $result = self::compile($css);
            self::assertTrue($result->ok(), $css . ': ' . implode(' | ', array_map('strval', $result->errors)));
            self::assertDoesNotMatchRegularExpression('/\[|:(first|last|nth|only|not|has|is|where)|[+~]|data-/', $result->css, $css);
            // Every compound that targets a button targets the class both buttons share.
            preg_match_all('/(?:^|[{},\s>])(\.k)(:[a-z-]+)*(?=[{,\s>])/', $result->css, $m);
            self::assertNotEmpty($m[1], $css);
        }
    }

    public function testContrastIsCheckedAgainstTheThemeColours(): void
    {
        $dark = ConsentThemeV2::fromStored(['colors' => ['background' => '#111827', 'text' => '#f9fafb', 'link' => '#93c5fd', 'accent' => '#fbbf24', 'accentText' => '#111827']]);
        self::assertTrue(self::compile('.body { color: #e5e7eb }', $dark)->ok());
        self::assertFalse(self::compile('.body { color: #374151 }', $dark)->ok());
        self::assertTrue(self::compile('.button { background-color: #f59e0b }', $dark)->ok(), 'dark text on a lighter amber');
        self::assertTrue(self::compile('.button { color: white; background: black }')->ok());
        self::assertTrue(self::compile('.button { color: inherit }')->ok(), 'keywords are not measured');
    }

    public function testParseColor(): void
    {
        self::assertSame('#aabbcc', CustomCssCompiler::parseColor('#ABC'));
        self::assertSame('#0b3a8f', CustomCssCompiler::parseColor('#0B3A8F'));
        self::assertSame('#112233', CustomCssCompiler::parseColor('rgb(17, 34, 51)'));
        self::assertSame('#112233', CustomCssCompiler::parseColor('rgb(17 34 51 / 1)'));
        self::assertSame('#ff0000', CustomCssCompiler::parseColor('hsl(0, 100%, 50%)'));
        self::assertSame('#00ff00', CustomCssCompiler::parseColor('hsl(120deg 100% 50%)'));
        self::assertSame('#ffffff', CustomCssCompiler::parseColor('white'));
        self::assertNull(CustomCssCompiler::parseColor('rgb(300, 0, 0)'));
        self::assertNull(CustomCssCompiler::parseColor('rgba(0, 0, 0, .5)'));
        self::assertNull(CustomCssCompiler::parseColor('linear-gradient(#000, #fff)'));
    }
}
