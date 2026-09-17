<?php

declare(strict_types=1);

namespace Analytics\Shared\Crypto;

final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
