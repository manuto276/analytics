<?php

declare(strict_types=1);

namespace Analytics\Tracking\Application;

use Analytics\Shared\Types;
use Analytics\Sites\Application\SiteSnapshot;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Erases the cookie-level data of one visitor id (the id only exists in that visitor's browser).
 * Base-level rows are not linked to the visitor and stay aggregated.
 */
final readonly class ForgetService
{
    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    /** @return int rows affected */
    public function forget(SiteSnapshot $site, string $visitorId): int
    {
        $types = [ParameterType::INTEGER, ParameterType::BINARY];
        $params = [$site->id, $visitorId];

        return $this->connection->transactional(function (Connection $c) use ($site, $params, $types): int {
            $days = array_map(Types::string(...), $c->fetchFirstColumn('SELECT DISTINCT local_day FROM visits WHERE site_id = ? AND visitor_id = ?', $params, $types));
            $affected = 0;
            $affected += (int) $c->executeStatement("DELETE FROM events_raw WHERE site_id = ? AND visitor_id = ? AND level = 'c'", $params, $types);
            $affected += (int) $c->executeStatement('DELETE l FROM visit_lookup l JOIN visits v ON v.id = l.visit_id AND v.local_day = l.visit_day WHERE v.site_id = ? AND v.visitor_id = ?', $params, $types);
            $affected += (int) $c->executeStatement('DELETE FROM visits WHERE site_id = ? AND visitor_id = ?', $params, $types);
            $affected += (int) $c->executeStatement('DELETE FROM attribution_touches WHERE site_id = ? AND visitor_id = ?', $params, $types);
            $affected += (int) $c->executeStatement('DELETE FROM visitors WHERE site_id = ? AND visitor_id = ?', $params, $types);
            $affected += (int) $c->executeStatement('DELETE FROM consent_receipts WHERE site_id = ? AND visitor_id = ?', $params, $types);
            $affected += (int) $c->executeStatement('UPDATE conversions SET visitor_id = NULL WHERE site_id = ? AND visitor_id = ?', $params, $types);
            foreach ($days as $day) {
                DirtyDayMarker::mark($c, $site->id, $day, $this->clock->now());
            }

            return $affected;
        });
    }
}
