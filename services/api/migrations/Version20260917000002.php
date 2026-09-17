<?php

declare(strict_types=1);

namespace Analytics\Migrations;

use Analytics\Shared\Doctrine\Partitioning;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Hot and analytic tables accessed with DBAL only (raw SQL, monthly partitions, no foreign keys).
 * Set DB_PARTITIONING=false for servers where partitioning is unavailable.
 */
final class Version20260917000002 extends AbstractMigration
{
    private const string TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function getDescription(): string
    {
        return 'Analytic, ingestion and rollup tables (DBAL, partitioned)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $flag = $_ENV['DB_PARTITIONING'] ?? $_SERVER['DB_PARTITIONING'] ?? getenv('DB_PARTITIONING');
        $partitioning = filter_var(\is_string($flag) && $flag !== '' ? $flag : 'true', \FILTER_VALIDATE_BOOL);
        $partitions = $partitioning ? "\n" . Partitioning::createClause(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) : '';
        $o = self::TABLE_OPTIONS;

        $this->addSql('CREATE TABLE cache_items (
            item_id VARBINARY(255) NOT NULL,
            item_data MEDIUMBLOB NOT NULL,
            item_lifetime INT UNSIGNED DEFAULT NULL,
            item_time INT UNSIGNED NOT NULL,
            PRIMARY KEY (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');

        $this->addSql("CREATE TABLE daily_salts (
            day DATE NOT NULL,
            salt BINARY(32) NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (day)
        ) {$o}");

        $utm = 'utm_source VARCHAR(100) DEFAULT NULL, utm_medium VARCHAR(100) DEFAULT NULL, utm_campaign VARCHAR(100) DEFAULT NULL, utm_content VARCHAR(100) DEFAULT NULL, utm_term VARCHAR(100) DEFAULT NULL';

        $this->addSql("CREATE TABLE events_raw (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            local_day DATE NOT NULL,
            site_id INT UNSIGNED NOT NULL,
            event_uid BINARY(12) NOT NULL,
            occurred_at DATETIME(3) NOT NULL,
            received_at DATETIME(3) NOT NULL,
            level CHAR(1) CHARACTER SET ascii NOT NULL,
            type CHAR(2) CHARACTER SET ascii NOT NULL,
            name VARCHAR(64) DEFAULT NULL,
            visit_id BIGINT UNSIGNED DEFAULT NULL,
            is_entry TINYINT(1) NOT NULL DEFAULT 0,
            visitor_hash BIGINT UNSIGNED DEFAULT NULL,
            visitor_id BINARY(16) DEFAULT NULL,
            host VARCHAR(190) NOT NULL,
            path VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            page_hash BINARY(8) NOT NULL,
            query VARCHAR(1024) DEFAULT NULL,
            referrer_host VARCHAR(190) DEFAULT NULL,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            {$utm},
            browser VARCHAR(32) DEFAULT NULL,
            browser_major SMALLINT UNSIGNED DEFAULT NULL,
            os VARCHAR(32) DEFAULT NULL,
            os_major SMALLINT UNSIGNED DEFAULT NULL,
            device VARCHAR(16) DEFAULT NULL,
            country CHAR(2) CHARACTER SET ascii DEFAULT NULL,
            content_key VARCHAR(128) DEFAULT NULL,
            engagement_ms INT UNSIGNED DEFAULT NULL,
            scroll_pct TINYINT UNSIGNED DEFAULT NULL,
            props JSON DEFAULT NULL,
            PRIMARY KEY (id, local_day),
            UNIQUE KEY uniq_events_uid (site_id, event_uid, local_day),
            KEY idx_events_site_day_type (site_id, local_day, type),
            KEY idx_events_site_received (site_id, received_at),
            KEY idx_events_site_visitor (site_id, visitor_id, local_day),
            KEY idx_events_visit (site_id, visit_id)
        ) {$o}{$partitions}");

        $this->addSql("CREATE TABLE visits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            local_day DATE NOT NULL,
            site_id INT UNSIGNED NOT NULL,
            level CHAR(1) CHARACTER SET ascii NOT NULL,
            started_at DATETIME(3) NOT NULL,
            last_activity_at DATETIME(3) NOT NULL,
            visitor_hash BIGINT UNSIGNED DEFAULT NULL,
            visitor_id BINARY(16) DEFAULT NULL,
            entry_host VARCHAR(190) NOT NULL,
            entry_path VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            entry_page_hash BINARY(8) NOT NULL,
            exit_page_hash BINARY(8) NOT NULL,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            events INT UNSIGNED NOT NULL DEFAULT 0,
            engagement_ms INT UNSIGNED NOT NULL DEFAULT 0,
            is_bounce TINYINT(1) NOT NULL DEFAULT 1,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            referrer_host VARCHAR(190) DEFAULT NULL,
            {$utm},
            browser VARCHAR(32) DEFAULT NULL,
            os VARCHAR(32) DEFAULT NULL,
            device VARCHAR(16) DEFAULT NULL,
            country CHAR(2) CHARACTER SET ascii DEFAULT NULL,
            entry_content_key VARCHAR(128) DEFAULT NULL,
            PRIMARY KEY (id, local_day),
            KEY idx_visits_site_day (site_id, local_day),
            KEY idx_visits_site_visitor (site_id, visitor_id, started_at),
            KEY idx_visits_site_started (site_id, started_at)
        ) {$o}{$partitions}");

        $this->addSql("CREATE TABLE visit_lookup (
            site_id INT UNSIGNED NOT NULL,
            visitor_key BINARY(16) NOT NULL,
            visit_id BIGINT UNSIGNED NOT NULL,
            visit_day DATE NOT NULL,
            last_activity_at DATETIME(3) NOT NULL,
            source_key BINARY(8) DEFAULT NULL,
            PRIMARY KEY (site_id, visitor_key),
            KEY idx_visit_lookup_activity (last_activity_at)
        ) {$o}");

        $this->addSql("CREATE TABLE visitors (
            site_id INT UNSIGNED NOT NULL,
            visitor_id BINARY(16) NOT NULL,
            first_seen_at DATETIME(3) NOT NULL,
            last_seen_at DATETIME(3) NOT NULL,
            first_touch_id BIGINT UNSIGNED DEFAULT NULL,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            consent_version INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, visitor_id),
            KEY idx_visitors_last_seen (last_seen_at)
        ) {$o}");

        $this->addSql("CREATE TABLE attribution_touches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            visitor_id BINARY(16) NOT NULL,
            visit_id BIGINT UNSIGNED NOT NULL,
            visit_day DATE NOT NULL,
            touched_at DATETIME(3) NOT NULL,
            is_first TINYINT(1) NOT NULL DEFAULT 0,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            referrer_host VARCHAR(190) DEFAULT NULL,
            {$utm},
            landing_host VARCHAR(190) NOT NULL,
            landing_path VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            PRIMARY KEY (id),
            KEY idx_touches_site_visitor (site_id, visitor_id, touched_at),
            KEY idx_touches_touched (touched_at)
        ) {$o}");

        $attr = 'VARCHAR(100) DEFAULT NULL';
        $this->addSql("CREATE TABLE conversions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            external_id VARCHAR(128) NOT NULL,
            name VARCHAR(64) NOT NULL,
            origin VARCHAR(8) CHARACTER SET ascii NOT NULL,
            occurred_at DATETIME(3) NOT NULL,
            local_day DATE NOT NULL,
            received_at DATETIME(3) NOT NULL,
            visitor_id BINARY(16) DEFAULT NULL,
            customer_ref BINARY(32) DEFAULT NULL,
            value_minor BIGINT DEFAULT NULL,
            currency CHAR(3) CHARACTER SET ascii DEFAULT NULL,
            props JSON DEFAULT NULL,
            attr_model_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            attr_via VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'none',
            attr_touch_id BIGINT UNSIGNED DEFAULT NULL,
            attr_touched_at DATETIME(3) DEFAULT NULL,
            attr_channel VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'unattributed',
            attr_source {$attr},
            attr_utm_source {$attr},
            attr_utm_medium {$attr},
            attr_utm_campaign {$attr},
            lnd_touch_id BIGINT UNSIGNED DEFAULT NULL,
            lnd_touched_at DATETIME(3) DEFAULT NULL,
            lnd_channel VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'unattributed',
            lnd_source {$attr},
            lnd_utm_source {$attr},
            lnd_utm_medium {$attr},
            lnd_utm_campaign {$attr},
            declared_source JSON DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_conversions_external (site_id, external_id),
            KEY idx_conversions_site_name_day (site_id, name, local_day),
            KEY idx_conversions_site_day (site_id, local_day),
            KEY idx_conversions_customer (site_id, customer_ref, occurred_at),
            KEY idx_conversions_visitor (site_id, visitor_id)
        ) {$o}");

        $this->addSql("CREATE TABLE consent_stats_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            consent_version INT UNSIGNED NOT NULL,
            shown INT UNSIGNED NOT NULL DEFAULT 0,
            accepted INT UNSIGNED NOT NULL DEFAULT 0,
            rejected INT UNSIGNED NOT NULL DEFAULT 0,
            dismissed INT UNSIGNED NOT NULL DEFAULT 0,
            reopened INT UNSIGNED NOT NULL DEFAULT 0,
            changed_to_accept INT UNSIGNED NOT NULL DEFAULT 0,
            changed_to_reject INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, consent_version)
        ) {$o}");

        $this->addSql("CREATE TABLE consent_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT UNSIGNED NOT NULL,
            visitor_id BINARY(16) NOT NULL,
            consent_version INT UNSIGNED NOT NULL,
            decision VARCHAR(8) CHARACTER SET ascii NOT NULL,
            decided_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_consent_receipts_visitor (site_id, visitor_id),
            KEY idx_consent_receipts_decided (decided_at)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_dirty (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            marked_at DATETIME(3) NOT NULL,
            PRIMARY KEY (site_id, day)
        ) {$o}");

        $this->addSql("CREATE TABLE job_runs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job VARCHAR(64) NOT NULL,
            started_at DATETIME(3) NOT NULL,
            finished_at DATETIME(3) DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            message VARCHAR(1000) DEFAULT NULL,
            stats JSON DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_job_runs_job_started (job, started_at)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_overview_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED DEFAULT NULL,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            engagement_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            events INT UNSIGNED NOT NULL DEFAULT 0,
            consented_visits INT UNSIGNED NOT NULL DEFAULT 0,
            conversions INT UNSIGNED NOT NULL DEFAULT 0,
            revenue_minor BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_pages_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            page_hash BINARY(8) NOT NULL,
            host VARCHAR(190) NOT NULL,
            path VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            entries INT UNSIGNED NOT NULL DEFAULT 0,
            exits INT UNSIGNED NOT NULL DEFAULT 0,
            entry_bounces INT UNSIGNED NOT NULL DEFAULT 0,
            engagement_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, page_hash)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_landing_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            page_hash BINARY(8) NOT NULL,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            host VARCHAR(190) NOT NULL,
            path VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            entries INT UNSIGNED NOT NULL DEFAULT 0,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, page_hash, channel)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_sources_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            source_hash BINARY(8) NOT NULL,
            source VARCHAR(100) DEFAULT NULL,
            referrer_host VARCHAR(190) DEFAULT NULL,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            engagement_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, channel, source_hash)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_campaigns_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            utm_hash BINARY(8) NOT NULL,
            {$utm},
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            bounces INT UNSIGNED NOT NULL DEFAULT 0,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, utm_hash)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_tech_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            dimension VARCHAR(16) CHARACTER SET ascii NOT NULL,
            value VARCHAR(64) NOT NULL,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, dimension, value)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_geo_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            country CHAR(2) CHARACTER SET ascii NOT NULL,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, country)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_events_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            name VARCHAR(64) NOT NULL,
            prop_key VARCHAR(32) NOT NULL,
            prop_value_hash BINARY(8) NOT NULL,
            prop_value VARCHAR(100) NOT NULL,
            occurrences INT UNSIGNED NOT NULL DEFAULT 0,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, name, prop_key, prop_value_hash)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_content_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            content_key VARCHAR(128) NOT NULL,
            channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            pageviews INT UNSIGNED NOT NULL DEFAULT 0,
            visits INT UNSIGNED NOT NULL DEFAULT 0,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            contacts INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, content_key, channel)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_conversions_daily (
            site_id INT UNSIGNED NOT NULL,
            day DATE NOT NULL,
            name VARCHAR(64) NOT NULL,
            attr_channel VARCHAR(16) CHARACTER SET ascii NOT NULL,
            attr_hash BINARY(8) NOT NULL,
            attr_source VARCHAR(100) DEFAULT NULL,
            attr_utm_source VARCHAR(100) DEFAULT NULL,
            attr_utm_medium VARCHAR(100) DEFAULT NULL,
            attr_utm_campaign VARCHAR(100) DEFAULT NULL,
            count INT UNSIGNED NOT NULL DEFAULT 0,
            value_minor BIGINT NOT NULL DEFAULT 0,
            attributed INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, day, name, attr_channel, attr_hash)
        ) {$o}");

        $this->addSql("CREATE TABLE rollup_consented_visitors_monthly (
            site_id INT UNSIGNED NOT NULL,
            month DATE NOT NULL,
            dimension VARCHAR(16) CHARACTER SET ascii NOT NULL,
            value_hash BINARY(8) NOT NULL,
            value VARCHAR(100) NOT NULL,
            visitors INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (site_id, month, dimension, value_hash)
        ) {$o}");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Migrations are forward-only.');
    }
}
