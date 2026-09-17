<?php

declare(strict_types=1);

namespace Analytics\Tracking\Infrastructure;

use Analytics\Tracking\Application\EventDraft;
use Analytics\Tracking\Application\EventSink;
use Predis\ClientInterface;

/**
 * INGEST_MODE=queue: anonymised drafts are pushed to Redis and written by queue:work.
 */
final readonly class RedisQueueEventSink implements EventSink
{
    public const string KEY = 'ingest';

    public function __construct(private ClientInterface $redis) {}

    public function accept(array $drafts): void
    {
        if ($drafts === []) {
            return;
        }
        $this->redis->rpush(self::KEY, array_map(static fn(EventDraft $d): string => json_encode($d->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), $drafts));
    }

    /** @return list<EventDraft> */
    public function pop(int $max): array
    {
        $items = [];
        for ($i = 0; $i < $max; ++$i) {
            $raw = $this->redis->lpop(self::KEY);
            if (!\is_string($raw)) {
                break;
            }
            $data = json_decode($raw, true);
            if (\is_array($data)) {
                try {
                    $items[] = EventDraft::fromArray($data);
                } catch (\Throwable) {
                    // Malformed entries are dropped.
                }
            }
        }

        return $items;
    }

    public function length(): int
    {
        return (int) $this->redis->llen(self::KEY);
    }
}
