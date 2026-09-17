<?php

declare(strict_types=1);

namespace Analytics\Conversions\Application;

use Analytics\Shared\Types;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Attributes a conversion, in order:
 *   1. the attribution of the earliest attributed conversion with the same customer_ref;
 *   2. the visitor's touches: first touch, and last non-direct touch before the conversion;
 *   3. nothing ("unattributed", still counted).
 * The result is snapshotted on the conversion row with the model version.
 */
final readonly class AttributionResolver
{
    public const int MODEL_VERSION = 1;

    public function __construct(private Connection $connection) {}

    /**
     * @return array<string, mixed> columns to store on the conversion row
     */
    public function resolve(int $siteId, ?string $visitorId, ?string $customerRefHash, \DateTimeImmutable $occurredAt): array
    {
        $unattributed = [
            'attr_model_version' => self::MODEL_VERSION,
            'attr_via' => 'none',
            'attr_touch_id' => null,
            'attr_touched_at' => null,
            'attr_channel' => 'unattributed',
            'attr_source' => null,
            'attr_utm_source' => null,
            'attr_utm_medium' => null,
            'attr_utm_campaign' => null,
            'lnd_touch_id' => null,
            'lnd_touched_at' => null,
            'lnd_channel' => 'unattributed',
            'lnd_source' => null,
            'lnd_utm_source' => null,
            'lnd_utm_medium' => null,
            'lnd_utm_campaign' => null,
        ];
        $at = $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');

        if ($visitorId !== null) {
            $first = $this->touch($siteId, $visitorId, $at, true);
            $last = $this->touch($siteId, $visitorId, $at, false);
            if ($first !== null || $last !== null) {
                return array_merge($unattributed, [
                    'attr_via' => 'visitor',
                    'attr_touch_id' => $first['id'] ?? null,
                    'attr_touched_at' => $first['touched_at'] ?? null,
                    'attr_channel' => $first['channel'] ?? 'unattributed',
                    'attr_source' => $first['source'] ?? null,
                    'attr_utm_source' => $first['utm_source'] ?? null,
                    'attr_utm_medium' => $first['utm_medium'] ?? null,
                    'attr_utm_campaign' => $first['utm_campaign'] ?? null,
                    'lnd_touch_id' => $last['id'] ?? null,
                    'lnd_touched_at' => $last['touched_at'] ?? null,
                    'lnd_channel' => $last['channel'] ?? 'unattributed',
                    'lnd_source' => $last['source'] ?? null,
                    'lnd_utm_source' => $last['utm_source'] ?? null,
                    'lnd_utm_medium' => $last['utm_medium'] ?? null,
                    'lnd_utm_campaign' => $last['utm_campaign'] ?? null,
                ]);
            }
        }

        if ($customerRefHash !== null) {
            $previous = $this->connection->fetchAssociative(
                "SELECT attr_touch_id, attr_touched_at, attr_channel, attr_source, attr_utm_source, attr_utm_medium, attr_utm_campaign,
                        lnd_touch_id, lnd_touched_at, lnd_channel, lnd_source, lnd_utm_source, lnd_utm_medium, lnd_utm_campaign
                   FROM conversions
                  WHERE site_id = ? AND customer_ref = ? AND attr_channel <> 'unattributed'
                  ORDER BY occurred_at ASC, id ASC LIMIT 1",
                [$siteId, $customerRefHash],
                [ParameterType::INTEGER, ParameterType::BINARY],
            );
            if (\is_array($previous)) {
                return array_merge($unattributed, $previous, ['attr_via' => 'customer_ref', 'attr_model_version' => self::MODEL_VERSION]);
            }
        }

        return $unattributed;
    }

    /** @return array<string, mixed>|null */
    private function touch(int $siteId, string $visitorId, string $occurredAt, bool $first): ?array
    {
        $condition = $first ? '' : " AND channel NOT IN ('direct', 'internal')";
        $order = $first ? 'touched_at ASC, id ASC' : 'touched_at DESC, id DESC';
        $row = $this->connection->fetchAssociative(
            'SELECT id, touched_at, channel, source, utm_source, utm_medium, utm_campaign
               FROM attribution_touches
              WHERE site_id = ? AND visitor_id = ? AND touched_at <= ?' . $condition . '
              ORDER BY ' . $order . ' LIMIT 1',
            [$siteId, $visitorId, $occurredAt],
            [ParameterType::INTEGER, ParameterType::BINARY, ParameterType::STRING],
        );
        if (!\is_array($row)) {
            return null;
        }
        $row['id'] = Types::int($row['id']);

        return $row;
    }
}
