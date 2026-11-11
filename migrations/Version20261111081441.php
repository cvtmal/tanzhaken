<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The password_reset_request table of cycle #11. The DDL of each branch is Doctrine's own
 * dump of the mapping (SQLite, and MariaDB 10.11 without a server), so the generated FK name
 * matches the mapping and `doctrine:schema:validate` stays green.
 */
final class Version20261111081441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the password_reset_request table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            $this->addSql('CREATE TABLE password_reset_request (id BLOB NOT NULL, token_hash CHAR(64) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, teacher_id BLOB NOT NULL, PRIMARY KEY (id), CONSTRAINT FK_C5D0A95A41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_password_reset_request_teacher ON password_reset_request (teacher_id)');
            $this->addSql('CREATE UNIQUE INDEX uk_password_reset_request_token_hash ON password_reset_request (token_hash)');

            return;
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE password_reset_request (id BINARY(16) NOT NULL, token_hash CHAR(64) NOT NULL, requested_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, teacher_id BINARY(16) NOT NULL, INDEX idx_password_reset_request_teacher (teacher_id), UNIQUE INDEX uk_password_reset_request_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE password_reset_request ADD CONSTRAINT FK_C5D0A95A41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher (id) ON DELETE CASCADE');

            return;
        }

        $this->abortIf(true, 'SQLite or MariaDB/MySQL only');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset_request');
    }

    /**
     * DDL auto-commits on MySQL-family platforms, so a transaction there would only pretend.
     */
    public function isTransactional(): bool
    {
        return !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
