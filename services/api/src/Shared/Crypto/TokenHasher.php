<?php

declare(strict_types=1);

namespace Analytics\Shared\Crypto;

final class TokenHasher
{
    /** Raw 32-byte SHA-256 of a high-entropy token (suitable for lookup columns). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    public static function equals(string $known, string $token): bool
    {
        return hash_equals($known, self::hash($token));
    }
}
