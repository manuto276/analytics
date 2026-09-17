<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application;

use Analytics\Reporting\Domain\DateRange;
use Analytics\Reporting\Domain\ReportQuery;
use Analytics\Shared\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * Report cache keyed by site, report, parameters and the site's rollup_version, so a rollup run
 * invalidates everything for that site. Ranges that include today expire after a minute.
 */
final readonly class ReportCache
{
    public const int TTL_LIVE = 60;
    public const int TTL_HISTORIC = 86400;
    public const int TTL_REALTIME = 10;

    public function __construct(
        private TagAwareAdapterInterface $cache,
        private ClockInterface $clock,
    ) {}

    /**
     * @param callable(): array<string, mixed> $compute
     *
     * @return array{data: array<string, mixed>, cache: string}
     */
    public function remember(string $report, ReportQuery $query, int $rollupVersion, callable $compute): array
    {
        $key = 'report.' . $report . '.' . $query->site->id . '.' . $rollupVersion . '.' . $query->fingerprint($report);
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $value = $item->get();
            if (\is_array($value)) {
                $data = [];
                foreach ($value as $name => $entry) {
                    $data[Types::string($name)] = $entry;
                }

                return ['data' => $data, 'cache' => 'hit'];
            }
        }
        $data = $compute();
        $item->set($data)->expiresAfter($this->ttl($report, $query->range));
        $item->tag(['site-' . $query->site->id]);
        $this->cache->save($item);

        return ['data' => $data, 'cache' => 'miss'];
    }

    public function invalidateSite(int $siteId): void
    {
        $this->cache->invalidateTags(['site-' . $siteId]);
    }

    private function ttl(string $report, DateRange $range): int
    {
        if ($report === 'realtime') {
            return self::TTL_REALTIME;
        }
        $today = $this->clock->now()->setTimezone($range->to->getTimezone())->setTime(0, 0);

        return $range->contains($today) || $range->to > $today ? self::TTL_LIVE : self::TTL_HISTORIC;
    }
}
