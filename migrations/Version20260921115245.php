<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Soft deletion of a travail: `deleted_at` and who wrote it.
 *
 * Nothing is backfilled - every existing travail is live, which `deleted_at IS NULL` already says.
 */
final class Version20260921115245 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Soft deletion of an assignment (deleted_at, deleted_by_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment ADD deleted_at DATETIME DEFAULT NULL, ADD deleted_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAC76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BAC76F1F52 ON assignment (deleted_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAC76F1F52');
        $this->addSql('DROP INDEX IDX_30C544BAC76F1F52 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP deleted_at, DROP deleted_by_id');
    }
}
