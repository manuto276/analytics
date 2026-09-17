<?php

declare(strict_types=1);

namespace Analytics\Shared\Crypto;

/**
 * Authenticated encryption (XChaCha20-Poly1305) with a key ring.
 * Ciphertext format: "v1.<keyId>.<base64url(nonce || ciphertext)>". The last key in the ring encrypts.
 */
final readonly class SecretBox
{
    /** @param array<string, string> $keys key id => 32-byte key */
    public function __construct(private array $keys)
    {
        if ($keys === []) {
            throw new \InvalidArgumentException('SecretBox needs at least one key.');
        }
        foreach ($keys as $id => $key) {
            if (\strlen($key) !== \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \InvalidArgumentException(\sprintf('Key "%s" must be 32 bytes.', $id));
            }
        }
    }

    public function activeKeyId(): string
    {
        return (string) array_key_last($this->keys);
    }

    public function encrypt(string $plaintext, string $associatedData = ''): string
    {
        $keyId = $this->activeKeyId();
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $associatedData . '|' . $keyId, $nonce, $this->keys[$keyId]);

        return 'v1.' . $keyId . '.' . Base64Url::encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext, string $associatedData = ''): string
    {
        $parts = explode('.', $ciphertext, 3);
        if (\count($parts) !== 3 || $parts[0] !== 'v1') {
            throw new \RuntimeException('Unsupported ciphertext format.');
        }
        [, $keyId, $payload] = $parts;
        if (!isset($this->keys[$keyId])) {
            throw new \RuntimeException(\sprintf('Unknown key id "%s".', $keyId));
        }
        $raw = Base64Url::decode($payload);
        $nonceLength = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === null || \strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('Malformed ciphertext.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $nonceLength), $associatedData . '|' . $keyId, substr($raw, 0, $nonceLength), $this->keys[$keyId]);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plain;
    }

    public function keyIdOf(string $ciphertext): ?string
    {
        $parts = explode('.', $ciphertext, 3);

        return \count($parts) === 3 ? $parts[1] : null;
    }

    public function needsReencryption(string $ciphertext): bool
    {
        return $this->keyIdOf($ciphertext) !== $this->activeKeyId();
    }
}
