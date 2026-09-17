<?php

declare(strict_types=1);

namespace Analytics\Tests\Unit\Shared;

use Analytics\Shared\Net\IpTruncator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpTruncatorTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function cases(): iterable
    {
        yield 'ipv4' => ['192.0.2.123', '192.0.2.0/24'];
        yield 'ipv4 boundary' => ['198.51.100.255', '198.51.100.0/24'];
        yield 'ipv6' => ['2001:db8:abcd:1234:5678::1', '2001:db8:abcd::/48'];
        yield 'ipv6 uppercase' => ['2001:DB8:ABCD:FFFF::', '2001:db8:abcd::/48'];
        yield 'ipv6 bracketed' => ['[2001:db8:1:2::3]', '2001:db8:1::/48'];
        yield 'ipv6 zone id' => ['fe80::1%eth0', 'fe80::/48'];
        yield 'ipv4-mapped ipv6' => ['::ffff:203.0.113.9', '203.0.113.0/24'];
        yield 'loopback' => ['127.0.0.1', '127.0.0.0/24'];
        yield 'invalid' => ['not-an-ip', null];
        yield 'empty' => ['', null];
        yield 'ipv4 with port' => ['192.0.2.1:8080', null];
        yield 'out of range' => ['256.1.1.1', null];
    }

    #[DataProvider('cases')]
    public function testTruncate(string $input, ?string $expected): void
    {
        $prefix = IpTruncator::truncate($input);
        self::assertSame($expected, $prefix === null ? null : (string) $prefix);
    }

    public function testPrefixNeverContainsHostBits(): void
    {
        for ($i = 0; $i < 200; ++$i) {
            $ip = random_int(1, 223) . '.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(0, 255);
            $prefix = IpTruncator::truncate($ip);
            self::assertNotNull($prefix);
            self::assertStringEndsWith('.0/24', (string) $prefix);
            self::assertFalse($prefix->isV6());
        }
        $v6 = IpTruncator::truncate(inet_ntop(random_bytes(16)) ?: '::1');
        self::assertNotNull($v6);
        self::assertSame(str_repeat("\0", 10), substr($v6->packed, 6));
    }

    public function testMatchesCidrs(): void
    {
        $prefix = IpTruncator::truncate('192.0.2.200');
        self::assertNotNull($prefix);
        self::assertTrue($prefix->matches('192.0.2.0/24'));
        self::assertTrue($prefix->matches('192.0.0.0/16'));
        self::assertTrue($prefix->matches('192.0.2.17'));
        self::assertFalse($prefix->matches('192.0.3.0/24'));
        self::assertFalse($prefix->matches('2001:db8::/32'));
        self::assertFalse($prefix->matches('garbage'));

        $v6 = IpTruncator::truncate('2001:db8:1:2::1');
        self::assertNotNull($v6);
        self::assertTrue($v6->matches('2001:db8::/32'));
        self::assertTrue($v6->matches('2001:db8:1::/48'));
        self::assertFalse($v6->matches('2001:db8:2::/48'));
    }

    public function testMaskPacked(): void
    {
        self::assertSame("\xc0\x00\x02\x80", IpTruncator::maskPacked("\xc0\x00\x02\xff", 25));
        self::assertSame("\x00\x00\x00\x00", IpTruncator::maskPacked("\xff\xff\xff\xff", 0));
    }
}
