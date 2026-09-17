<?php

declare(strict_types=1);

namespace Analytics\Reporting\Application\Rollup;

/**
 * Per-day aggregate SELECTs over raw data. The rollup builder inserts them for one day; the query
 * planner runs the same SQL over a range (with filters) when no rollup can answer a query, so both
 * paths produce identical numbers by construction.
 *
 * Every method takes the WHERE tails to append (starting with " AND …") for events_raw (alias e)
 * and visits (alias v); the caller supplies site and day/range conditions.
 */
final class RawSelects
{
    public const string VISITORS_V = 'COUNT(DISTINCT v.visitor_hash) + COUNT(DISTINCT CASE WHEN v.visitor_hash IS NULL THEN v.visitor_id END)';
    public const string VISITORS_E = 'COUNT(DISTINCT e.visitor_hash) + COUNT(DISTINCT CASE WHEN e.visitor_hash IS NULL THEN e.visitor_id END)';

    /** Visits-side daily metrics. Columns: day, visits, visitors, bounces, engagement_ms, consented_visits, visit_pageviews. */
    public static function visitMetrics(string $visitsWhere): string
    {
        $visitors = self::VISITORS_V;

        return <<<SQL
            SELECT v.local_day AS day, COUNT(*) AS visits, {$visitors} AS visitors,
                   COALESCE(SUM(v.is_bounce), 0) AS bounces, COALESCE(SUM(v.engagement_ms), 0) AS engagement_ms,
                   COALESCE(SUM(v.level = 'c'), 0) AS consented_visits, COALESCE(SUM(v.pageviews), 0) AS visit_pageviews
              FROM visits v
             WHERE v.site_id = :site {$visitsWhere}
             GROUP BY v.local_day
            SQL;
    }

    /** Events-side daily metrics. Columns: day, pageviews, events, orphan_entries. */
    public static function eventMetrics(string $eventsWhere, bool $joinVisits = false): string
    {
        $join = $joinVisits ? ' LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day' : '';

        return <<<SQL
            SELECT e.local_day AS day, COALESCE(SUM(e.type = 'pv'), 0) AS pageviews, COALESCE(SUM(e.type = 'ev'), 0) AS events,
                   COALESCE(SUM(e.type = 'pv' AND e.visit_id IS NULL AND e.is_entry = 1), 0) AS orphan_entries
              FROM events_raw e{$join}
             WHERE e.site_id = :site {$eventsWhere}
             GROUP BY e.local_day
            SQL;
    }

    /** Conversions daily metrics. Columns: day, conversions, revenue_minor. */
    public static function conversionMetrics(string $where = ''): string
    {
        return <<<SQL
            SELECT c.local_day AS day, COUNT(*) AS conversions, COALESCE(SUM(IF(c.currency = :currency, c.value_minor, 0)), 0) AS revenue_minor
              FROM conversions c
             WHERE c.site_id = :site {$where}
             GROUP BY c.local_day
            SQL;
    }

    /** Columns: day, page_hash, host, path, pageviews, visits, visitors, entries, exits, entry_bounces, engagement_ms. */
    public static function pages(string $eventsWhere, string $visitsWhere, bool $joinVisits = false): string
    {
        $visitorsE = self::VISITORS_E;
        $join = $joinVisits ? ' LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day' : '';

        return <<<SQL
            SELECT e.local_day AS day, e.page_hash, MAX(e.host) AS host, MAX(e.path) AS path, COUNT(*) AS pageviews,
                   COUNT(DISTINCT e.visit_id) + COALESCE(SUM(e.visit_id IS NULL AND e.is_entry = 1), 0) AS visits,
                   {$visitorsE} AS visitors,
                   COALESCE(SUM(e.visit_id IS NULL AND e.is_entry = 1), 0) AS entries, 0 AS exits, 0 AS entry_bounces, 0 AS engagement_ms
              FROM events_raw e{$join}
             WHERE e.site_id = :site AND e.type = 'pv' {$eventsWhere}
             GROUP BY e.local_day, e.page_hash
            UNION ALL
            SELECT v.local_day, v.entry_page_hash, MAX(v.entry_host), MAX(v.entry_path), 0, 0, 0, COUNT(*), 0, COALESCE(SUM(v.is_bounce), 0), 0
              FROM visits v
             WHERE v.site_id = :site AND v.pageviews > 0 {$visitsWhere}
             GROUP BY v.local_day, v.entry_page_hash
            UNION ALL
            SELECT v.local_day, v.exit_page_hash, NULL, NULL, 0, 0, 0, 0, COUNT(*), 0, 0
              FROM visits v
             WHERE v.site_id = :site AND v.pageviews > 0 {$visitsWhere}
             GROUP BY v.local_day, v.exit_page_hash
            UNION ALL
            SELECT e.local_day, e.page_hash, MAX(e.host), MAX(e.path), 0, 0, 0, 0, 0, 0, COALESCE(SUM(e.engagement_ms), 0)
              FROM events_raw e{$join}
             WHERE e.site_id = :site AND e.type = 'en' {$eventsWhere}
             GROUP BY e.local_day, e.page_hash
            SQL;
    }

    /** Columns: day, page_hash, channel, host, path, entries, bounces. */
    public static function landing(string $eventsWhere, string $visitsWhere): string
    {
        return <<<SQL
            SELECT v.local_day AS day, v.entry_page_hash AS page_hash, v.channel, MAX(v.entry_host) AS host, MAX(v.entry_path) AS path,
                   COUNT(*) AS entries, COALESCE(SUM(v.is_bounce), 0) AS bounces
              FROM visits v
             WHERE v.site_id = :site AND v.pageviews > 0 {$visitsWhere}
             GROUP BY v.local_day, v.entry_page_hash, v.channel
            UNION ALL
            SELECT e.local_day, e.page_hash, e.channel, MAX(e.host), MAX(e.path), COUNT(*), 0
              FROM events_raw e
             WHERE e.site_id = :site AND e.type = 'pv' AND e.visit_id IS NULL AND e.is_entry = 1 {$eventsWhere}
             GROUP BY e.local_day, e.page_hash, e.channel
            SQL;
    }

    /** Columns: day, channel, source_hash, source, referrer_host, visits, visitors, bounces, pageviews, engagement_ms. */
    public static function sources(string $eventsWhere, string $visitsWhere): string
    {
        $visitors = self::VISITORS_V;
        $hash = "UNHEX(SUBSTRING(SHA2(CONCAT_WS('|', IFNULL(%s.source, ''), IFNULL(%s.referrer_host, '')), 256), 1, 16))";

        return \sprintf(<<<SQL
            SELECT v.local_day AS day, v.channel, %s AS source_hash, MAX(v.source) AS source, MAX(v.referrer_host) AS referrer_host,
                   COUNT(*) AS visits, {$visitors} AS visitors, COALESCE(SUM(v.is_bounce), 0) AS bounces,
                   COALESCE(SUM(v.pageviews), 0) AS pageviews, COALESCE(SUM(v.engagement_ms), 0) AS engagement_ms
              FROM visits v
             WHERE v.site_id = :site {$visitsWhere}
             GROUP BY v.local_day, v.channel, source_hash
            UNION ALL
            SELECT e.local_day, e.channel, %s, MAX(e.source), MAX(e.referrer_host), COUNT(*), 0, 0, COUNT(*), 0
              FROM events_raw e
             WHERE e.site_id = :site AND e.type = 'pv' AND e.visit_id IS NULL AND e.is_entry = 1 {$eventsWhere}
             GROUP BY e.local_day, e.channel, 3
            SQL, \sprintf($hash, 'v', 'v'), \sprintf($hash, 'e', 'e'));
    }

    /** Columns: day, utm_hash, utm_source, utm_medium, utm_campaign, utm_content, utm_term, visits, visitors, bounces, pageviews. */
    public static function campaigns(string $visitsWhere): string
    {
        $visitors = self::VISITORS_V;

        return <<<SQL
            SELECT v.local_day AS day,
                   UNHEX(SUBSTRING(SHA2(CONCAT_WS('|', IFNULL(v.utm_source, ''), IFNULL(v.utm_medium, ''), IFNULL(v.utm_campaign, ''), IFNULL(v.utm_content, ''), IFNULL(v.utm_term, '')), 256), 1, 16)) AS utm_hash,
                   MAX(v.utm_source) AS utm_source, MAX(v.utm_medium) AS utm_medium, MAX(v.utm_campaign) AS utm_campaign,
                   MAX(v.utm_content) AS utm_content, MAX(v.utm_term) AS utm_term,
                   COUNT(*) AS visits, {$visitors} AS visitors, COALESCE(SUM(v.is_bounce), 0) AS bounces, COALESCE(SUM(v.pageviews), 0) AS pageviews
              FROM visits v
             WHERE v.site_id = :site AND (v.utm_source IS NOT NULL OR v.utm_medium IS NOT NULL OR v.utm_campaign IS NOT NULL) {$visitsWhere}
             GROUP BY v.local_day, utm_hash
            SQL;
    }

    /** Columns: day, value, visits, visitors, pageviews. $dimension is one of device, browser, os. */
    public static function tech(string $dimension, string $eventsWhere, string $visitsWhere): string
    {
        $visitors = self::VISITORS_V;

        return <<<SQL
            SELECT v.local_day AS day, IFNULL(v.{$dimension}, '') AS value, COUNT(*) AS visits, {$visitors} AS visitors, 0 AS pageviews
              FROM visits v
             WHERE v.site_id = :site {$visitsWhere}
             GROUP BY v.local_day, value
            UNION ALL
            SELECT e.local_day, IFNULL(e.{$dimension}, ''), COALESCE(SUM(e.visit_id IS NULL AND e.is_entry = 1), 0), 0, COUNT(*)
              FROM events_raw e
             WHERE e.site_id = :site AND e.type = 'pv' {$eventsWhere}
             GROUP BY e.local_day, 2
            SQL;
    }

    /** Columns: day, country, visits, visitors, pageviews. */
    public static function geo(string $eventsWhere, string $visitsWhere): string
    {
        $visitors = self::VISITORS_V;

        return <<<SQL
            SELECT v.local_day AS day, IFNULL(v.country, 'ZZ') AS country, COUNT(*) AS visits, {$visitors} AS visitors, 0 AS pageviews
              FROM visits v
             WHERE v.site_id = :site {$visitsWhere}
             GROUP BY v.local_day, country
            UNION ALL
            SELECT e.local_day, IFNULL(e.country, 'ZZ'), COALESCE(SUM(e.visit_id IS NULL AND e.is_entry = 1), 0), 0, COUNT(*)
              FROM events_raw e
             WHERE e.site_id = :site AND e.type = 'pv' {$eventsWhere}
             GROUP BY e.local_day, 2
            SQL;
    }

    /** Columns: day, name, prop_key, prop_value_hash, prop_value, occurrences, visits. */
    public static function events(string $eventsWhere, bool $joinVisits = false): string
    {
        $join = $joinVisits ? ' LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day' : '';

        return <<<SQL
            SELECT e.local_day AS day, e.name, '' AS prop_key, UNHEX('0000000000000000') AS prop_value_hash, '' AS prop_value,
                   COUNT(*) AS occurrences, COUNT(DISTINCT e.visit_id) + COALESCE(SUM(e.visit_id IS NULL), 0) AS visits
              FROM events_raw e{$join}
             WHERE e.site_id = :site AND e.type = 'ev' AND e.name IS NOT NULL {$eventsWhere}
             GROUP BY e.local_day, e.name
            SQL;
    }

    /** Columns: day, name, prop_key, prop_value_hash, prop_value, occurrences, visits. */
    public static function eventProps(string $eventsWhere, bool $joinVisits = false): string
    {
        $join = $joinVisits ? ' LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day' : '';

        return <<<SQL
            SELECT day, name, prop_key, UNHEX(SUBSTRING(SHA2(prop_value, 256), 1, 16)) AS prop_value_hash, MAX(prop_value) AS prop_value,
                   COUNT(*) AS occurrences, COUNT(DISTINCT visit_id) + COALESCE(SUM(visit_id IS NULL), 0) AS visits
              FROM (
                SELECT e.local_day AS day, e.name, e.visit_id, CAST(k.prop_key AS CHAR(32)) AS prop_key,
                       LEFT(IF(JSON_TYPE(JSON_EXTRACT(e.props, CONCAT('$."', k.prop_key, '"'))) = 'STRING',
                               JSON_UNQUOTE(JSON_EXTRACT(e.props, CONCAT('$."', k.prop_key, '"'))),
                               CAST(JSON_EXTRACT(e.props, CONCAT('$."', k.prop_key, '"')) AS CHAR)), 100) AS prop_value
                  FROM events_raw e{$join}
                  JOIN JSON_TABLE(JSON_KEYS(e.props), '$[*]' COLUMNS (prop_key VARCHAR(32) PATH '$')) k
                 WHERE e.site_id = :site AND e.type = 'ev' AND e.name IS NOT NULL AND e.props IS NOT NULL {$eventsWhere}
              ) props
             GROUP BY day, name, prop_key, prop_value_hash
            SQL;
    }

    /** Columns: day, content_key, channel, pageviews, visits, visitors, contacts. */
    public static function content(string $eventsWhere, string $visitsWhere = ''): string
    {
        $visitorsE = self::VISITORS_E;

        return <<<SQL
            SELECT e.local_day AS day, e.content_key, COALESCE(v.channel, e.channel) AS channel, COUNT(*) AS pageviews,
                   COUNT(DISTINCT e.visit_id) + COALESCE(SUM(e.visit_id IS NULL AND e.is_entry = 1), 0) AS visits,
                   {$visitorsE} AS visitors, 0 AS contacts
              FROM events_raw e LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day{$visitsWhere}
             WHERE e.site_id = :site AND e.type = 'pv' AND e.content_key IS NOT NULL {$eventsWhere}
             GROUP BY e.local_day, e.content_key, 3
            UNION ALL
            SELECT e.local_day, e.content_key, COALESCE(v.channel, e.channel), 0, 0, 0, COUNT(*)
              FROM events_raw e LEFT JOIN visits v ON v.site_id = e.site_id AND v.id = e.visit_id AND v.local_day = e.local_day{$visitsWhere}
             WHERE e.site_id = :site AND e.type = 'ev' AND e.content_key IS NOT NULL AND e.name IN (:contacts) {$eventsWhere}
             GROUP BY e.local_day, e.content_key, 3
            SQL;
    }

    /** Columns: day, name, attr_channel, attr_hash, attr_source, attr_utm_source, attr_utm_medium, attr_utm_campaign, count, value_minor, attributed. */
    public static function conversions(string $where = ''): string
    {
        return <<<SQL
            SELECT c.local_day AS day, c.name, c.attr_channel,
                   UNHEX(SUBSTRING(SHA2(CONCAT_WS('|', IFNULL(c.attr_source, ''), IFNULL(c.attr_utm_source, ''), IFNULL(c.attr_utm_medium, ''), IFNULL(c.attr_utm_campaign, '')), 256), 1, 16)) AS attr_hash,
                   MAX(c.attr_source) AS attr_source, MAX(c.attr_utm_source) AS attr_utm_source, MAX(c.attr_utm_medium) AS attr_utm_medium, MAX(c.attr_utm_campaign) AS attr_utm_campaign,
                   COUNT(*) AS count, COALESCE(SUM(IF(c.currency = :currency, c.value_minor, 0)), 0) AS value_minor,
                   COALESCE(SUM(c.attr_channel <> 'unattributed'), 0) AS attributed
              FROM conversions c
             WHERE c.site_id = :site {$where}
             GROUP BY c.local_day, c.name, c.attr_channel, attr_hash
            SQL;
    }
}
