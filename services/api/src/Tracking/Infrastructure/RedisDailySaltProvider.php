<?php

declare(strict_types=1);

namespace Analytics\Tracking\Infrastructure;

use Analytics\Tracking\Application\DailySaltProvider;
use Predis\ClientInterface;

/**
 * Salt in Redis "salt:{day}" with EXPIREAT next UTC midnight (keys are prefixed "an:").
 */
final class RedisDailySaltProvider implements DailySaltProvider
{
    private ?string $day = null;
    private ?string $salt = null;

    public function __construct(private readonly ClientInterface $redis) {}

    public function saltFor(\DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $day = $utc->format('Y-m-d');
        if ($this->day === $day && $this->salt !== null) {
            return $this->salt;
        }
        $key = 'salt:' . $day;
        // A relative TTL up to the next UTC midnight, measured on our clock. An absolute EXPIREAT
        // would be measured on Redis's clock instead, and wherever the two disagree by more than the
        // time left in the day the salt would be deleted the moment it was written.
        $ttl = max(1, $utc->modify('tomorrow')->getTimestamp() - $utc->getTimestamp());
        $this->redis->set($key, random_bytes(32), 'EX', $ttl, 'NX');
        $salt = $this->redis->get($key);
        if (!\is_string($salt) || \strlen($salt) !== 32) {
            throw new \RuntimeException('Daily salt unavailable.');
        }
        $this->redis->del(['salt:' . $utc->modify('-1 day')->format('Y-m-d')]);
        $this->day = $day;
        $this->salt = $salt;

        return $salt;
    }

    public function rotate(\DateTimeImmutable $now): int
    {
        $this->day = null;
        $this->salt = null;
        $this->saltFor($now);

        return 0;
    }
}
