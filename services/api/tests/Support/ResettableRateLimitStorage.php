<?php

declare(strict_types=1);

namespace Analytics\Tests\Support;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

final class ResettableRateLimitStorage implements StorageInterface
{
    private InMemoryStorage $inner;

    public function __construct()
    {
        $this->inner = new InMemoryStorage();
    }

    public function reset(): void
    {
        $this->inner = new InMemoryStorage();
    }

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->inner->save($limiterState);
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        return $this->inner->fetch($limiterStateId);
    }

    public function delete(string $limiterStateId): void
    {
        $this->inner->delete($limiterStateId);
    }
}
