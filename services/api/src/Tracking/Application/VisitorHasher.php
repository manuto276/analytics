<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Net\IpPrefix;

final class VisitorHasher
{
    /**
     * BLAKE2b(site id ‖ shortened IP ‖ user agent, key = today's salt), 16 bytes.
     * Only the shortened IP is ever used; the UA is not stored.
     */
    public static function hash(int $siteId, ?IpPrefix $ip, string $userAgent, string $salt): string
    {
        return sodium_crypto_generichash(pack('N', $siteId) . ($ip === null ? '' : $ip->packed) . "\0" . $userAgent, $salt, 16);
    }

    /** First 8 bytes as a non-negative 63-bit integer (fits BIGINT UNSIGNED and PHP int). */
    public static function toInt(string $hash): int
    {
        /** @var array{1: int} $parts */
        $parts = unpack('J', substr($hash, 0, 8));

        return $parts[1] & \PHP_INT_MAX;
    }
}
