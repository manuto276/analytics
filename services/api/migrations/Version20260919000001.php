<?php

declare(strict_types=1);

namespace Analytics\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Pending email changes (ORM-mapped, Identity\Domain\EmailChange). Same shape as password_resets:
 * only the SHA-256 of the confirmation token is stored, with its expiry (24 h) and the time it was
 * used. Expand-only: a new table that the previous release simply ignores.
 */
final class Version20260919000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pending email changes (ORM)';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_changes (used_at DATETIME DEFAULT NULL, token_hash BINARY(32) NOT NULL, user_id INT UNSIGNED NOT NULL, new_email VARCHAR(190) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, INDEX idx_email_changes_user (user_id), PRIMARY KEY (token_hash)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Migrations are forward-only.');
    }
}
