<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Reports;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Live view from raw events: active visitors (5 minutes), pageviews per minute (30 minutes),
 * top pages and sources of the last 30 minutes.
 */
final readonly class RealtimeReport
{
    public const int ACTIVE_MINUTES = 5;
    public const int WINDOW_MINUTES = 30;

    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    /** @return array<string, mixed> */
    public function run(SiteSnapshot $site): array
    {
        $now = $this->clock->now()->setTimezone($site->timezone());
        $offset = $now->format('P');
        $params = [
            'site' => $site->id,
            'active' => $now->modify('-' . self::ACTIVE_MINUTES . ' minutes')->format('Y-m-d H:i:s.v'),
            'window' => $now->modify('-' . self::WINDOW_MINUTES . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            'offset' => $offset,
        ];
        $params['active'] = $now->modify('-' . self::ACTIVE_MINUTES . ' minutes')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');

        $active = Types::int($this->connection->fetchOne(
            "SELECT COUNT(DISTINCT COALESCE(HEX(e.visitor_id), CAST(e.visitor_hash AS CHAR), CONCAT('e', e.id)))
               FROM events_raw e WHERE e.site_id = :site AND e.received_at >= :active",
            ['site' => $params['site'], 'active' => $params['active']],
        ));

        $perMinute = [];
        for ($minute = self::WINDOW_MINUTES - 1; $minute >= 0; --$minute) {
            $perMinute[$now->modify('-' . $minute . ' minutes')->format('Y-m-d H:i')] = 0;
        }
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DATE_FORMAT(CONVERT_TZ(e.received_at, '+00:00', :offset), '%Y-%m-%d %H:%i') AS minute, COUNT(*) AS pageviews
               FROM events_raw e WHERE e.site_id = :site AND e.received_at >= :window AND e.type = 'pv' GROUP BY minute",
            $params,
        );
        foreach ($rows as $row) {
            $key = Types::string($row['minute']);
            if (\array_key_exists($key, $perMinute)) {
                $perMinute[$key] = Types::int($row['pageviews']);
            }
        }

        unset($params['offset']);
        $topPages = array_map(static fn(array $r): array => [
            'host' => Types::string($r['host']),
            'path' => Types::string($r['path']),
            'pageviews' => Types::int($r['pageviews']),
        ], $this->connection->fetchAllAssociative(
            "SELECT MAX(e.host) AS host, MAX(e.path) AS path, COUNT(*) AS pageviews
               FROM events_raw e WHERE e.site_id = :site AND e.received_at >= :window AND e.type = 'pv'
              GROUP BY e.page_hash ORDER BY pageviews DESC, 1 ASC LIMIT 10",
            $params,
        ));

        $topSources = array_map(static fn(array $r): array => [
            'channel' => Types::string($r['channel']),
            'source' => Types::nullableString($r['source']),
            'visits' => Types::int($r['visits']),
        ], $this->connection->fetchAllAssociative(
            'SELECT v.channel, MAX(v.source) AS source, COUNT(*) AS visits
               FROM visits v WHERE v.site_id = :site AND v.started_at >= :window
              GROUP BY v.channel, IFNULL(v.source, \'\') ORDER BY visits DESC, 1 ASC LIMIT 10',
            $params,
        ));

        $points = [];
        foreach ($perMinute as $minute => $pageviews) {
            $points[] = ['t' => (string) $minute, 'pageviews' => $pageviews];
        }

        return [
            'active_visitors' => $active,
            'pageviews_per_minute' => $points,
            'top_pages' => $topPages,
            'top_sources' => $topSources,
        ];
    }
}
