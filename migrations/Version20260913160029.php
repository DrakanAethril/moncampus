<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a deposit of offers taught the platform about the sites it collects from.
 *
 * Since the list of sites was opened, the resolution never refuses: an unknown site is created, and
 * a known site met on an unknown host has that host attached to it. Both are right, and both are
 * silent - this table is the trace, so the two gestures can be read on a screen and undone if one
 * of them was wrong.
 *
 * Nothing reads it to decide anything, hence no unique constraint and no back reference: it is a
 * journal, and the only thing it owes is being ordered by date. Both foreign keys cascade because a
 * gesture about a site that no longer exists, or about a deposit somebody removed, has nothing left
 * to say.
 */
final class Version20260913160029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Journal of what the jobboard ingestion learned about its sources';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jobboard_source_learning (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(16) NOT NULL, declared VARCHAR(255) NOT NULL, domain VARCHAR(190) NOT NULL, learned_at DATETIME NOT NULL, batch_id INT NOT NULL, source_id INT NOT NULL, INDEX IDX_D45FCA55F39EBE7A (batch_id), INDEX IDX_D45FCA55953C1C61 (source_id), INDEX idx_jobboard_learning_at (learned_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE jobboard_source_learning ADD CONSTRAINT FK_D45FCA55F39EBE7A FOREIGN KEY (batch_id) REFERENCES jobboard_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jobboard_source_learning ADD CONSTRAINT FK_D45FCA55953C1C61 FOREIGN KEY (source_id) REFERENCES jobboard_source (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_source_learning DROP FOREIGN KEY FK_D45FCA55F39EBE7A');
        $this->addSql('ALTER TABLE jobboard_source_learning DROP FOREIGN KEY FK_D45FCA55953C1C61');
        $this->addSql('DROP TABLE jobboard_source_learning');
    }
}
