<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Sites;

use Analytics\Sites\Application\DomainMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainMatcherTest extends TestCase
{
    /** @return iterable<array{string, ?string}> */
    public static function hosts(): iterable
    {
        yield ['example.com', 'example.com'];
        yield ['https://WWW.Example.com/path?q=1', 'www.example.com'];
        yield ['example.com:8080', 'example.com'];
        yield ['example.com.', 'example.com'];
        yield ['localhost', 'localhost'];
        yield ['bücher.example', 'xn--bcher-kva.example'];
        yield ['', null];
        yield ['-bad-.com', null];
        yield ['exa mple.com', null];
        yield ['com', null];
    }

    #[DataProvider('hosts')]
    public function testNormalizeHost(string $input, ?string $expected): void
    {
        self::assertSame($expected, DomainMatcher::normalizeHost($input));
    }

    public function testMatches(): void
    {
        $domains = [['host' => 'example.com', 'include_subdomains' => true], ['host' => 'shop.example.net', 'include_subdomains' => false]];
        self::assertTrue(DomainMatcher::matches('example.com', $domains));
        self::assertTrue(DomainMatcher::matches('app.example.com', $domains));
        self::assertTrue(DomainMatcher::matches('SHOP.example.net', $domains));
        self::assertFalse(DomainMatcher::matches('www.shop.example.net', $domains));
        self::assertFalse(DomainMatcher::matches('evilexample.com', $domains));
        self::assertFalse(DomainMatcher::matches('example.com.evil.net', $domains));
        self::assertSame('example.com', DomainMatcher::hostOf('https://example.com/x'));
        self::assertNull(DomainMatcher::hostOf('null'));
    }
}
