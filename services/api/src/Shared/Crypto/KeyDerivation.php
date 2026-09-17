<?php

declare(strict_types=1);

namespace Analytics\Shared\Crypto;

/**
 * Derives purpose-bound subkeys from APP_SECRET (BLAKE2b keyed hash).
 */
final readonly class KeyDerivation
{
    public function __construct(private string $masterSecret) {}

    public function derive(string $purpose, string $context = ''): string
    {
        return sodium_crypto_generichash($purpose . "\0" . $context, $this->masterSecret, 32);
    }
}
