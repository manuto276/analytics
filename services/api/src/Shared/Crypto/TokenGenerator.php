<?php

declare(strict_types=1);

namespace Analytics\Shared\Crypto;

final class TokenGenerator
{
    private const string ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * URL-safe base64 of $bytes random bytes, without padding.
     *
     * @param positive-int $bytes
     */
    public static function base64Url(int $bytes = 32): string
    {
        return Base64Url::encode(random_bytes($bytes));
    }

    public static function alphanumeric(int $length): string
    {
        $out = '';
        $max = \strlen(self::ALPHANUMERIC) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $out .= self::ALPHANUMERIC[random_int(0, $max)];
        }

        return $out;
    }

    public static function lowerAlphanumeric(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, 35)];
        }

        return $out;
    }
}
