<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Analytics\Shared\Crypto\Base64Url;

/**
 * Builders for tracking payload v1.
 */
final class Payloads
{
    public const string CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    public const string IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    public static function uid(): string
    {
        return Base64Url::encode(random_bytes(12));
    }

    public static function id22(): string
    {
        return Base64Url::encode(random_bytes(16));
    }

    /** @param array<string, mixed> $extra */
    public static function pageview(string $url = 'https://www.site.test/', ?string $referrer = null, array $extra = []): array
    {
        return ['id' => self::uid(), 't' => 'pv', 'u' => $url, 'a' => 0] + ($referrer === null ? [] : ['r' => $referrer]) + $extra;
    }

    /** @param array<string, mixed> $props */
    public static function event(string $name, array $props = [], string $url = 'https://www.site.test/'): array
    {
        return ['id' => self::uid(), 't' => 'ev', 'u' => $url, 'n' => $name, 'p' => $props, 'a' => 0];
    }

    public static function engagement(int $ms, int $scroll = 50, string $url = 'https://www.site.test/'): array
    {
        return ['id' => self::uid(), 't' => 'en', 'u' => $url, 'ms' => $ms, 'sp' => $scroll, 'a' => 0];
    }

    public static function consentStat(string $kind, string $url = 'https://www.site.test/'): array
    {
        return ['id' => self::uid(), 't' => 'cs', 'u' => $url, 'cs' => $kind, 'a' => 0];
    }

    public static function consentUpgrade(string $landing, ?string $landingReferrer = null, string $url = ''): array
    {
        return ['id' => self::uid(), 't' => 'cu', 'u' => $url === '' ? $landing : $url, 'lu' => $landing, 'a' => 0] + ($landingReferrer === null ? [] : ['lr' => $landingReferrer]);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>       $extra
     */
    public static function batch(string $publicKey, array $events, string $level = 'b', array $extra = []): array
    {
        return $extra + ['v' => 1, 'k' => $publicKey, 'l' => $level, 'cv' => 0, 'sw' => 1440, 'e' => $events];
    }
}
