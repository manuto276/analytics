<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

/**
 * argon2id via password_hash, with a libsodium fallback when PHP lacks argon2 support.
 */
final class PasswordHasher
{
    public const int MIN_LENGTH = 12;

    private ?string $dummyHash = null;

    public function __construct(private readonly bool $fast = false) {}

    public function hash(string $password): string
    {
        if (\defined('PASSWORD_ARGON2ID')) {
            $options = $this->fast
                ? ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]
                : ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

            return password_hash($password, \PASSWORD_ARGON2ID, $options);
        }

        return sodium_crypto_pwhash_str(
            $password,
            $this->fast ? \SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE : \SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            \SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
        );
    }

    public function verify(string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$argon2id$') && !\defined('PASSWORD_ARGON2ID')) {
            return sodium_crypto_pwhash_str_verify($hash, $password);
        }

        return password_verify($password, $hash);
    }

    /** A constant-time dummy verification to hide whether an account exists. */
    public function dummyVerify(string $password): void
    {
        $this->dummyHash ??= $this->hash('dummy-password-for-timing');
        $this->verify($password, $this->dummyHash);
    }

    /** @return list<string> policy violations */
    public static function validatePolicy(string $password, string $email = ''): array
    {
        $errors = [];
        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors[] = \sprintf('Must be at least %d characters.', self::MIN_LENGTH);
        }
        if (\strlen($password) > 1024) {
            $errors[] = 'Must be at most 1024 bytes.';
        }
        if ($email !== '' && stripos($password, explode('@', $email)[0]) !== false && \strlen(explode('@', $email)[0]) >= 4) {
            $errors[] = 'Must not contain your email name.';
        }
        if (\count(array_unique(mb_str_split($password))) < 5) {
            $errors[] = 'Is too repetitive.';
        }

        return $errors;
    }
}
