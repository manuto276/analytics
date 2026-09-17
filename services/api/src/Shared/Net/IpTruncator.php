<?php

declare(strict_types=1);

namespace Analytics\Shared\Net;

final class IpTruncator
{
    public const int V4_BITS = 24;
    public const int V6_BITS = 48;

    /**
     * Shortens an address: IPv4 to /24, IPv6 to /48; IPv4-mapped IPv6 is treated as IPv4.
     * Returns null for invalid input.
     */
    public static function truncate(string $ip): ?IpPrefix
    {
        $ip = trim($ip);
        if (str_starts_with($ip, '[') && str_contains($ip, ']')) {
            $ip = substr($ip, 1, (int) strpos($ip, ']') - 1);
        }
        $zone = strpos($ip, '%');
        if ($zone !== false) {
            $ip = substr($ip, 0, $zone);
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (\strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $bits = \strlen($packed) === 4 ? self::V4_BITS : self::V6_BITS;

        return IpPrefix::fromPacked(self::maskPacked($packed, $bits), $bits);
    }

    public static function maskPacked(string $packed, int $bits): string
    {
        $out = '';
        $len = \strlen($packed);
        for ($i = 0; $i < $len; ++$i) {
            $remaining = $bits - $i * 8;
            if ($remaining >= 8) {
                $out .= $packed[$i];
            } elseif ($remaining <= 0) {
                $out .= "\0";
            } else {
                $mask = (0xFF << (8 - $remaining)) & 0xFF;
                $out .= \chr(\ord($packed[$i]) & $mask);
            }
        }

        return $out;
    }
}
