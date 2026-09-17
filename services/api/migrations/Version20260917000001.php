<?php

declare(strict_types=1);

namespace Analytics\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Configuration and identity tables (ORM-mapped). Generated from the entity mapping and reviewed.
 */
final class Version20260917000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuration and identity tables (ORM)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, actor_type VARCHAR(16) NOT NULL, actor_id INT UNSIGNED DEFAULT NULL, action VARCHAR(64) NOT NULL, site_id INT UNSIGNED DEFAULT NULL, target_type VARCHAR(32) DEFAULT NULL, target_id VARCHAR(64) DEFAULT NULL, metadata JSON NOT NULL, ip_prefix VARCHAR(64) DEFAULT NULL, INDEX idx_audit_log_site_time (site_id, occurred_at), INDEX idx_audit_log_actor_time (actor_type, actor_id, occurred_at), INDEX idx_audit_log_time (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE site_domains (id INT UNSIGNED AUTO_INCREMENT NOT NULL, host VARCHAR(190) NOT NULL, include_subdomains TINYINT NOT NULL, created_at DATETIME NOT NULL, site_id INT UNSIGNED NOT NULL, INDEX idx_site_domains_host (host), UNIQUE INDEX uniq_site_domains_site_host (site_id, host), INDEX IDX_77DF5D56F6BD1646 (site_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sites (id INT UNSIGNED AUTO_INCREMENT NOT NULL, public_key CHAR(24) NOT NULL, name VARCHAR(190) NOT NULL, timezone VARCHAR(64) NOT NULL, currency CHAR(3) NOT NULL, base_tracking_enabled TINYINT NOT NULL, visitor_hash_mode VARCHAR(20) NOT NULL, cookie_level_enabled TINYINT NOT NULL, cookie_domain VARCHAR(190) DEFAULT NULL, visitor_cookie_days SMALLINT UNSIGNED NOT NULL, new_visit_on_campaign_change TINYINT NOT NULL, dnt_mode VARCHAR(16) NOT NULL, respect_gpc TINYINT NOT NULL, hash_routing TINYINT NOT NULL, allow_localhost TINYINT NOT NULL, tracker_global VARCHAR(32) NOT NULL, allowed_query_params JSON NOT NULL, excluded_paths JSON NOT NULL, excluded_ip_prefixes JSON NOT NULL, content_contact_events JSON NOT NULL, auto_events JSON NOT NULL, min_group_size SMALLINT UNSIGNED NOT NULL, consent_receipts_enabled TINYINT NOT NULL, rollup_version INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, archived_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_sites_public_key (public_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE recovery_codes (id INT UNSIGNED AUTO_INCREMENT NOT NULL, used_at DATETIME DEFAULT NULL, user_id INT UNSIGNED NOT NULL, code_hash BINARY(32) NOT NULL, INDEX idx_recovery_codes_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE password_resets (used_at DATETIME DEFAULT NULL, token_hash BINARY(32) NOT NULL, user_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, INDEX idx_password_resets_user (user_id), PRIMARY KEY (token_hash)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE totp_credentials (confirmed_at DATETIME DEFAULT NULL, last_used_step BIGINT UNSIGNED DEFAULT NULL, user_id INT UNSIGNED NOT NULL, secret_ciphertext VARCHAR(255) NOT NULL, key_id VARCHAR(8) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE auth_sessions (revoked_at DATETIME DEFAULT NULL, id BINARY(32) NOT NULL, user_id INT UNSIGNED NOT NULL, state VARCHAR(16) NOT NULL, csrf_secret VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, idle_expires_at DATETIME NOT NULL, absolute_expires_at DATETIME NOT NULL, ip_prefix VARCHAR(64) DEFAULT NULL, ua_summary VARCHAR(120) DEFAULT NULL, INDEX idx_auth_sessions_user (user_id), INDEX idx_auth_sessions_absolute (absolute_expires_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT NOT NULL, email VARCHAR(190) NOT NULL, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(120) NOT NULL, global_role VARCHAR(16) NOT NULL, locale VARCHAR(8) NOT NULL, status VARCHAR(16) NOT NULL, failed_logins SMALLINT UNSIGNED NOT NULL, locked_until DATETIME DEFAULT NULL, password_changed_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_users_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user_site_roles (user_id INT UNSIGNED NOT NULL, site_id INT UNSIGNED NOT NULL, role VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_user_site_roles_site (site_id), PRIMARY KEY (user_id, site_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invitations (id INT UNSIGNED AUTO_INCREMENT NOT NULL, accepted_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, email VARCHAR(190) NOT NULL, token_hash BINARY(32) NOT NULL, global_role VARCHAR(16) NOT NULL, site_roles JSON NOT NULL, invited_by INT UNSIGNED DEFAULT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_invitations_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE consent_configs (id INT UNSIGNED AUTO_INCREMENT NOT NULL, published_at DATETIME DEFAULT NULL, published_by INT UNSIGNED DEFAULT NULL, site_id INT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL, consent_version INT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, texts JSON NOT NULL, policy_urls JSON NOT NULL, default_locale VARCHAR(8) NOT NULL, theme JSON NOT NULL, accepted_ttl_days SMALLINT UNSIGNED NOT NULL, rejected_ttl_days SMALLINT UNSIGNED NOT NULL, show_floating_reopen TINYINT NOT NULL, created_at DATETIME NOT NULL, INDEX idx_consent_configs_site_status (site_id, status), UNIQUE INDEX uniq_consent_configs_site_revision (site_id, revision), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE funnel_steps (position SMALLINT UNSIGNED NOT NULL, goal_id INT UNSIGNED NOT NULL, funnel_id INT UNSIGNED NOT NULL, INDEX idx_funnel_steps_goal (goal_id), INDEX IDX_B38550C07D958642 (funnel_id), PRIMARY KEY (funnel_id, position)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE goals (id INT UNSIGNED AUTO_INCREMENT NOT NULL, site_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(16) NOT NULL, `match` JSON NOT NULL, UNIQUE INDEX uniq_goals_site_name (site_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE funnels (id INT UNSIGNED AUTO_INCREMENT NOT NULL, site_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, scope VARCHAR(16) NOT NULL, window_days SMALLINT UNSIGNED NOT NULL, UNIQUE INDEX uniq_funnels_site_name (site_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE api_keys (id INT UNSIGNED AUTO_INCREMENT NOT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, site_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, prefix CHAR(8) NOT NULL, secret_hash BINARY(32) NOT NULL, scopes JSON NOT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, INDEX idx_api_keys_site (site_id), UNIQUE INDEX uniq_api_keys_prefix (prefix), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE campaign_costs (id INT UNSIGNED AUTO_INCREMENT NOT NULL, site_id INT UNSIGNED NOT NULL, day_from DATE NOT NULL, day_to DATE NOT NULL, channel VARCHAR(32) DEFAULT NULL, utm_source VARCHAR(100) DEFAULT NULL, utm_medium VARCHAR(100) DEFAULT NULL, utm_campaign VARCHAR(100) DEFAULT NULL, amount_minor BIGINT NOT NULL, currency CHAR(3) NOT NULL, note VARCHAR(255) DEFAULT NULL, import_batch_id VARCHAR(36) DEFAULT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_campaign_costs_site_day (site_id, day_from), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE site_domains ADD CONSTRAINT FK_77DF5D56F6BD1646 FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE funnel_steps ADD CONSTRAINT FK_B38550C07D958642 FOREIGN KEY (funnel_id) REFERENCES funnels (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Migrations are forward-only.');
    }
}
