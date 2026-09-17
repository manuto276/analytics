<?php

declare(strict_types=1);

namespace Analytics\Conversions\Application;

use Analytics\Shared\Crypto\KeyDerivation;

/**
 * Customer references are stored only as a keyed hash (per site), so they cannot be read back.
 */
final readonly class CustomerRefHasher
{
    public function __construct(private KeyDerivation $keys) {}

    public function hash(int $siteId, string $customerRef): string
    {
        $key = $this->keys->derive('customer_ref', (string) $siteId);

        return hash_hmac('sha256', trim($customerRef), $key, true);
    }
}
