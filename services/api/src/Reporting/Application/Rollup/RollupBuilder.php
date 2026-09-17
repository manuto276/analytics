<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Rollup;

use Analytics\Reporting\Application\DailyMetrics;
use Analytics\Reporting\Domain\DateRange;
use Analytics\Sites\Domain\SiteSnapshot;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Rebuilds every rollup of one (site, local day) from raw data: DELETE + INSERT … SELECT, using the
 * same SQL fragments the raw query path uses (RawSelects), so rollup and raw results agree.
 * All metrics are additive across days; "visitors" are visitor-days.
 */
final readonly class RollupBuilder
{
    public const array DAILY_TABLES = [
        'rollup_overview_daily',
        'rollup_pages_daily',
        'rollup_landing_daily',
        'rollup_sources_daily',
        'rollup_campaigns_daily',
        'rollup_tech_daily',
        'rollup_geo_daily',
        'rollup_events_daily',
        'rollup_content_daily',
        'rollup_conversions_daily',
    ];

    public function __construct(private Connection $connection, private DailyMetrics $metrics) {}

    public function build(SiteSnapshot $site, string $day): void
    {
        $p = ['site' => $site->id, 'day' => $day];
        foreach (self::DAILY_TABLES as $table) {
            $this->connection->executeStatement('DELETE FROM ' . $table . ' WHERE site_id = :site AND day = :day', $p);
        }
        $events = ' AND e.local_day = :day';
        $visits = ' AND v.local_day = :day';

        $dayRange = new DateRange(new \DateTimeImmutable($day), new \DateTimeImmutable($day));
        $totals = $this->metrics->raw($site, $dayRange)[$day] ?? DailyMetrics::EMPTY;
        if (array_sum($totals) > 0) {
            $this->connection->insert('rollup_overview_daily', ['site_id' => $site->id, 'day' => $day] + $totals);
        }

        $this->insertSelect(
            'rollup_pages_daily',
            ['page_hash', 'host', 'path', 'pageviews', 'visits', 'visitors', 'entries', 'exits', 'entry_bounces', 'engagement_ms'],
            'SELECT :site, day, page_hash, MAX(host), MAX(path), SUM(pageviews), SUM(visits), SUM(visitors), SUM(entries), SUM(exits), SUM(entry_bounces), SUM(engagement_ms)
               FROM (' . RawSelects::pages($events, $visits) . ') parts GROUP BY day, page_hash HAVING MAX(host) IS NOT NULL',
            $p,
        );

        $this->insertSelect(
            'rollup_landing_daily',
            ['page_hash', 'channel', 'host', 'path', 'entries', 'bounces'],
            'SELECT :site, day, page_hash, channel, MAX(host), MAX(path), SUM(entries), SUM(bounces)
               FROM (' . RawSelects::landing($events, $visits) . ') parts GROUP BY day, page_hash, channel',
            $p,
        );

        $this->insertSelect(
            'rollup_sources_daily',
            ['channel', 'source_hash', 'source', 'referrer_host', 'visits', 'visitors', 'bounces', 'pageviews', 'engagement_ms'],
            'SELECT :site, day, channel, source_hash, MAX(source), MAX(referrer_host), SUM(visits), SUM(visitors), SUM(bounces), SUM(pageviews), SUM(engagement_ms)
               FROM (' . RawSelects::sources($events, $visits) . ') parts GROUP BY day, channel, source_hash',
            $p,
        );

        $this->insertSelect(
            'rollup_campaigns_daily',
            ['utm_hash', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'visits', 'visitors', 'bounces', 'pageviews'],
            'SELECT :site, day, utm_hash, MAX(utm_source), MAX(utm_medium), MAX(utm_campaign), MAX(utm_content), MAX(utm_term), SUM(visits), SUM(visitors), SUM(bounces), SUM(pageviews)
               FROM (' . RawSelects::campaigns($visits) . ') parts GROUP BY day, utm_hash',
            $p,
        );

        foreach (['device', 'browser', 'os'] as $dimension) {
            $this->insertSelect(
                'rollup_tech_daily',
                ['dimension', 'value', 'visits', 'visitors', 'pageviews'],
                "SELECT :site, day, '{$dimension}', value, SUM(visits), SUM(visitors), SUM(pageviews)
                   FROM (" . RawSelects::tech($dimension, $events, $visits) . ') parts GROUP BY day, value',
                $p,
            );
        }

        $this->insertSelect(
            'rollup_geo_daily',
            ['country', 'visits', 'visitors', 'pageviews'],
            'SELECT :site, day, country, SUM(visits), SUM(visitors), SUM(pageviews)
               FROM (' . RawSelects::geo($events, $visits) . ') parts GROUP BY day, country',
            $p,
        );

        $this->insertSelect(
            'rollup_events_daily',
            ['name', 'prop_key', 'prop_value_hash', 'prop_value', 'occurrences', 'visits'],
            'SELECT :site, day, name, prop_key, prop_value_hash, MAX(prop_value), SUM(occurrences), SUM(visits)
               FROM (' . RawSelects::events($events) . ') parts GROUP BY day, name, prop_key, prop_value_hash',
            $p,
        );
        $this->insertSelect(
            'rollup_events_daily',
            ['name', 'prop_key', 'prop_value_hash', 'prop_value', 'occurrences', 'visits'],
            'SELECT :site, day, name, prop_key, prop_value_hash, MAX(prop_value), SUM(occurrences), SUM(visits)
               FROM (' . RawSelects::eventProps($events) . ') parts GROUP BY day, name, prop_key, prop_value_hash',
            $p,
        );

        $contacts = $site->contentContactEvents === [] ? ['__none__'] : $site->contentContactEvents;
        $this->insertSelect(
            'rollup_content_daily',
            ['content_key', 'channel', 'pageviews', 'visits', 'visitors', 'contacts'],
            'SELECT :site, day, content_key, channel, SUM(pageviews), SUM(visits), SUM(visitors), SUM(contacts)
               FROM (' . RawSelects::content($events) . ') parts GROUP BY day, content_key, channel',
            $p + ['contacts' => $contacts],
            ['contacts' => ArrayParameterType::STRING],
        );

        $this->insertSelect(
            'rollup_conversions_daily',
            ['name', 'attr_channel', 'attr_hash', 'attr_source', 'attr_utm_source', 'attr_utm_medium', 'attr_utm_campaign', 'count', 'value_minor', 'attributed'],
            'SELECT :site, day, name, attr_channel, attr_hash, MAX(attr_source), MAX(attr_utm_source), MAX(attr_utm_medium), MAX(attr_utm_campaign), SUM(count), SUM(value_minor), SUM(attributed)
               FROM (' . RawSelects::conversions(' AND c.local_day = :day') . ') parts GROUP BY day, name, attr_channel, attr_hash',
            $p + ['currency' => $site->currency],
        );

        $this->buildConsentedMonthly($site, substr($day, 0, 7) . '-01');
    }

    /**
     * @param list<string>                                                                       $columns
     * @param array<string, mixed>                                                               $params
     * @param array<string, ArrayParameterType|ParameterType|\Doctrine\DBAL\Types\Type|string>  $types
     */
    private function insertSelect(string $table, array $columns, string $select, array $params, array $types = []): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $table . ' (site_id, day, ' . implode(', ', $columns) . ') ' . $select,
            $params,
            $types,
        );
    }

    /** True uniques of consented visitors per month (total, channel and first-seen cohort). */
    public function buildConsentedMonthly(SiteSnapshot $site, string $month): void
    {
        $end = new \DateTimeImmutable($month)->modify('last day of this month')->format('Y-m-d');
        $p = ['site' => $site->id, 'month' => $month, 'end' => $end];
        $this->connection->executeStatement('DELETE FROM rollup_consented_visitors_monthly WHERE site_id = :site AND month = :month', $p);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO rollup_consented_visitors_monthly (site_id, month, dimension, value_hash, value, visitors)
            SELECT :site, :month, 'total', UNHEX('0000000000000000'), '', COUNT(DISTINCT v.visitor_id)
              FROM visits v WHERE v.site_id = :site AND v.local_day BETWEEN :month AND :end AND v.visitor_id IS NOT NULL
            HAVING COUNT(DISTINCT v.visitor_id) > 0
            SQL, $p);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO rollup_consented_visitors_monthly (site_id, month, dimension, value_hash, value, visitors)
            SELECT :site, :month, 'channel', UNHEX(SUBSTRING(SHA2(v.channel, 256), 1, 16)), v.channel, COUNT(DISTINCT v.visitor_id)
              FROM visits v WHERE v.site_id = :site AND v.local_day BETWEEN :month AND :end AND v.visitor_id IS NOT NULL
             GROUP BY v.channel
            SQL, $p);
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO rollup_consented_visitors_monthly (site_id, month, dimension, value_hash, value, visitors)
            SELECT :site, :month, 'cohort', UNHEX(SUBSTRING(SHA2(cohort, 256), 1, 16)), cohort, COUNT(*)
              FROM (
                SELECT DISTINCT v.visitor_id, DATE_FORMAT(vr.first_seen_at, '%Y-%m') AS cohort
                  FROM visits v JOIN visitors vr ON vr.site_id = v.site_id AND vr.visitor_id = v.visitor_id
                 WHERE v.site_id = :site AND v.local_day BETWEEN :month AND :end AND v.visitor_id IS NOT NULL
              ) active
             GROUP BY cohort
            SQL, $p);
    }

    /** @param list<string> $days */
    public function clearRollupsForDays(int $siteId, array $days): void
    {
        foreach (self::DAILY_TABLES as $table) {
            $this->connection->executeStatement('DELETE FROM ' . $table . ' WHERE site_id = ? AND day IN (?)', [$siteId, $days], [ParameterType::INTEGER, ArrayParameterType::STRING]);
        }
    }
}
