<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Tracking;

use Analytics\Tracking\Application\Enrichment\ReferrerClassifier;
use Analytics\Tracking\Application\Enrichment\UrlSanitizer;
use Analytics\Tracking\Application\Enrichment\UserAgentClassifier;
use PHPUnit\Framework\TestCase;

final class EnrichmentEdgeCasesTest extends TestCase
{
    public function testUrlSanitizerBoundaries(): void
    {
        self::assertNull(UrlSanitizer::sanitize('mailto:someone@example.com', [], false), 'no host');
        self::assertNull(UrlSanitizer::sanitize('', [], false));

        $rootless = UrlSanitizer::sanitize('https://example.com', [], false);
        self::assertNotNull($rootless);
        self::assertSame('/', $rootless['path'], 'an empty path becomes /');

        $trailingDot = UrlSanitizer::sanitize('https://Example.com./Path', [], false);
        self::assertNotNull($trailingDot);
        self::assertSame('example.com', $trailingDot['host']);

        $long = UrlSanitizer::sanitize('https://example.com/' . str_repeat('a', 2000), [], false);
        self::assertNotNull($long);
        self::assertSame(UrlSanitizer::MAX_PATH, mb_strlen($long['path']), 'paths are truncated');

        $manyParams = UrlSanitizer::sanitize('https://example.com/?' . http_build_query(array_fill_keys(range('a', 'z'), str_repeat('v', 200))), range('a', 'z'), false);
        self::assertNotNull($manyParams);
        self::assertLessThanOrEqual(UrlSanitizer::MAX_QUERY, \strlen((string) $manyParams['query']));

        $duplicate = UrlSanitizer::sanitize('https://example.com/?ref=first&ref=second&=empty', ['ref'], false);
        self::assertNotNull($duplicate);
        self::assertSame('ref=first', $duplicate['query'], 'the first value of a repeated parameter wins');

        $hashWithoutRoute = UrlSanitizer::sanitize('https://example.com/page#not-a-route', [], true);
        self::assertNotNull($hashWithoutRoute);
        self::assertSame('/page', $hashWithoutRoute['path'], 'only fragments that look like a route are kept');

        $hashRoute = UrlSanitizer::sanitize('https://example.com/app/#/orders?x=1', [], true);
        self::assertNotNull($hashRoute);
        self::assertSame('/app/#/orders', $hashRoute['path']);

        $encoded = UrlSanitizer::sanitize('https://example.com/caf%C3%A9/%7Euser%2Fx', [], false);
        self::assertNotNull($encoded);
        self::assertSame('/caf%C3%A9/~user%2Fx', $encoded['path'], 'only unreserved characters are decoded');
    }

    public function testReferrerClassifierHandlesUnknownAndMalformedCatalogues(): void
    {
        $classifier = new ReferrerClassifier(\dirname(__DIR__, 3) . '/resources/referrers');
        self::assertSame(['name' => 'Google', 'type' => 'search'], $classifier->classify('www.google.co.uk'));
        self::assertSame(['name' => 'Facebook', 'type' => 'social'], $classifier->classify('m.facebook.com'));
        self::assertSame(['name' => 'Bing', 'type' => 'search'], $classifier->classify('WWW.BING.COM'));
        self::assertNull($classifier->classify('blog.example.org'));

        $empty = new ReferrerClassifier('/does/not/exist');
        self::assertNull($empty->classify('www.google.com'));

        $dir = sys_get_temp_dir() . '/referrers-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/broken.json', '{not json');
        file_put_contents($dir . '/wrong-shape.json', '{"type": "search"}');
        file_put_contents($dir . '/ok.json', '{"type":"search","sources":{"Example":["search.example.com"],"Bad":"not-a-list"}}');
        try {
            $partial = new ReferrerClassifier($dir);
            self::assertSame(['name' => 'Example', 'type' => 'search'], $partial->classify('search.example.com'));
            self::assertNull($partial->classify('anything.else'));
        } finally {
            array_map('unlink', glob($dir . '/*.json') ?: []);
            rmdir($dir);
        }
    }

    public function testUserAgentClassifierMemoisesAndUsesScreenWidth(): void
    {
        $classifier = new UserAgentClassifier();
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
        $first = $classifier->classify($ua);
        self::assertSame($first, $classifier->classify($ua), 'the same UA returns the memoised result');

        // The memo is bounded: many different agents must not grow it without limit.
        for ($i = 0; $i < 600; ++$i) {
            $classifier->classify('Mozilla/5.0 (X11; Linux x86_64) CustomAgent/' . $i . '.0 Safari/537.36');
        }
        self::assertSame('Chrome', $classifier->classify($ua)->browser);

        $unknown = 'SomeBrowser/1.0 (Unknown device)';
        self::assertSame('mobile', $classifier->classify($unknown, 480)->device);
        self::assertSame('tablet', $classifier->classify($unknown, 900)->device);
        self::assertSame('desktop', $classifier->classify($unknown, 1600)->device);
        self::assertSame('other', $classifier->classify($unknown)->device);
    }
}
