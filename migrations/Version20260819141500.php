<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sharing a saved group batch with colleagues who teach the same class: a bare join table, the
 * same shape as progression_co_teacher, because the link is a permission rather than an authoring
 * act - nothing to date, nothing to revoke, nothing to annotate. Both sides cascade: a deleted lot
 * takes its shares with it, and so does a deleted teacher account.
 */
final class Version20260819141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partage d\'un lot de groupes avec les enseignants de la classe';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE group_batch_shared_teacher (group_batch_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_2ACB0FB0FAAEF205 (group_batch_id), INDEX IDX_2ACB0FB041807E1D (teacher_id), PRIMARY KEY(group_batch_id, teacher_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE group_batch_shared_teacher ADD CONSTRAINT FK_2ACB0FB0FAAEF205 FOREIGN KEY (group_batch_id) REFERENCES group_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_batch_shared_teacher ADD CONSTRAINT FK_2ACB0FB041807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_batch_shared_teacher DROP FOREIGN KEY FK_2ACB0FB0FAAEF205');
        $this->addSql('ALTER TABLE group_batch_shared_teacher DROP FOREIGN KEY FK_2ACB0FB041807E1D');
        $this->addSql('DROP TABLE group_batch_shared_teacher');
    }
}
