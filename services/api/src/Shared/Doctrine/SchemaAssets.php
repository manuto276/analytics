<?php

declare(strict_types=1);

namespace Analytics\Shared\Doctrine;

/**
 * Tables owned by raw-SQL migrations and accessed with DBAL only. They are hidden from ORM schema diffs.
 */
final class SchemaAssets
{
    public const array DBAL_TABLES = [
        'cache_items',
        'daily_salts',
        'events_raw',
        'visits',
        'visit_lookup',
        'visitors',
        'attribution_touches',
        'conversions',
        'consent_stats_daily',
        'consent_stat_uids',
        'consent_receipts',
        'rollup_dirty',
        'job_runs',
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
        'rollup_consented_visitors_monthly',
    ];

    public static function isOrmAsset(string $asset): bool
    {
        $name = $asset;
        $name = str_contains($name, '.') ? substr($name, (int) strrpos($name, '.') + 1) : $name;

        return !\in_array(trim($name, '`'), self::DBAL_TABLES, true);
    }
}
