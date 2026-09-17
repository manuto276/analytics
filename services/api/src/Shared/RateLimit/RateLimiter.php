<?php

declare(strict_types=1);

namespace Analytics\Shared\RateLimit;

use Analytics\Shared\Http\ApiProblem;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * Named rate-limit policies backed by Redis or the cache_items table.
 */
final class RateLimiter
{
    /** @var array<string, array{policy: string, limit: int, interval: string}> */
    public const array POLICIES = [
        'collect' => ['policy' => 'sliding_window', 'limit' => 300, 'interval' => '1 minute'],
        'collect_site' => ['policy' => 'sliding_window', 'limit' => 30000, 'interval' => '1 minute'],
        'script' => ['policy' => 'sliding_window', 'limit' => 600, 'interval' => '1 minute'],
        'login_ip' => ['policy' => 'sliding_window', 'limit' => 30, 'interval' => '15 minutes'],
        'login_email' => ['policy' => 'sliding_window', 'limit' => 10, 'interval' => '15 minutes'],
        'dashboard' => ['policy' => 'sliding_window', 'limit' => 600, 'interval' => '1 minute'],
        'server' => ['policy' => 'sliding_window', 'limit' => 1200, 'interval' => '1 minute'],
        'public' => ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 minute'],
    ];

    /** @var array<string, RateLimiterFactory> */
    private array $factories = [];

    /** @param array<string, array{limit?: int}> $overrides */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly array $overrides = [],
        private readonly bool $enabled = true,
    ) {}

    /**
     * Consumes one token; returns seconds to wait (0 when accepted).
     */
    public function hit(string $policy, string $key, int $tokens = 1): int
    {
        if (!$this->enabled) {
            return 0;
        }
        $limit = $this->factory($policy)->create($key)->consume($tokens);
        if ($limit->isAccepted()) {
            return 0;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }

    public function enforce(string $policy, string $key, int $tokens = 1): void
    {
        $wait = $this->hit($policy, $key, $tokens);
        if ($wait > 0) {
            throw ApiProblem::tooManyRequests($wait);
        }
    }

    public function reset(string $policy, string $key): void
    {
        $this->factory($policy)->create($key)->reset();
    }

    private function factory(string $policy): RateLimiterFactory
    {
        if (!isset($this->factories[$policy])) {
            $config = self::POLICIES[$policy] ?? throw new \InvalidArgumentException('Unknown rate-limit policy ' . $policy);
            $limit = $this->overrides[$policy]['limit'] ?? $config['limit'];
            $this->factories[$policy] = new RateLimiterFactory([
                'id' => 'rl_' . $policy,
                'policy' => $config['policy'],
                'limit' => $limit,
                'interval' => $config['interval'],
            ], $this->storage);
        }

        return $this->factories[$policy];
    }
}
