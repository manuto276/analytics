<?php

declare(strict_types=1);

namespace Analytics\Audit\Application;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Appends to audit_log. Metadata is redacted: keys that look like secrets are never stored.
 */
final readonly class AuditLogger
{
    private const array REDACTED_KEYS = ['password', 'secret', 'token', 'code', 'key', 'totp', 'recovery'];

    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    /** @param array<string, mixed> $metadata */
    public function log(
        string $action,
        ?Actor $actor,
        ?int $siteId = null,
        ?string $targetType = null,
        string|int|null $targetId = null,
        array $metadata = [],
    ): void {
        $this->connection->insert('audit_log', [
            'occurred_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'actor_type' => $actor->type ?? 'system',
            'actor_id' => $actor?->id,
            'action' => $action,
            'site_id' => $siteId,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'metadata' => json_encode(self::redact($metadata), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'ip_prefix' => $actor?->ipPrefix,
        ]);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                foreach (self::REDACTED_KEYS as $needle) {
                    if (str_contains(strtolower($key), $needle) && !\in_array(strtolower($key), ['key_prefix', 'public_key', 'content_key'], true)) {
                        $data[$key] = '[redacted]';
                        continue 2;
                    }
                }
            }
            if (\is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
