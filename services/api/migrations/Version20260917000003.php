<?php

declare(strict_types=1);

namespace Analytics\Migrations;

use Analytics\Shared\Doctrine\Partitioning;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Idempotency keys for consent statistics. `cs` events are counters only and are never written to
 * events_raw, so they had no protection against a retried sendBeacon; this table gives them the same
 * "count a uid once per site and local day" guarantee, and is partitioned and purged like the other
 * raw tables. Set DB_PARTITIONING=false for servers where partitioning is unavailable.
 */
final class Version20260917000003 extends AbstractMigration
{
    private const string TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function getDescription(): string
    {
        return 'Consent statistic idempotency keys (DBAL, partitioned)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $partitions = Partitioning::isEnabled() ? "\n" . Partitioning::createClause(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) : '';
        $o = self::TABLE_OPTIONS;

        $this->addSql("CREATE TABLE consent_stat_uids (
            site_id INT UNSIGNED NOT NULL,
            local_day DATE NOT NULL,
            event_uid BINARY(12) NOT NULL,
            PRIMARY KEY (site_id, local_day, event_uid)
        ) {$o}{$partitions}");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Migrations are forward-only.');
    }
}
