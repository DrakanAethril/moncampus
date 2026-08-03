<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803064427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Trace la consultation d'un travail par un étudiant (suivi de lecture).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignment_view (id INT AUTO_INCREMENT NOT NULL, first_viewed_at DATETIME NOT NULL, last_viewed_at DATETIME NOT NULL, view_count INT NOT NULL, assignment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_A5FB6612D19302F8 (assignment_id), INDEX IDX_A5FB6612CB944F1A (student_id), UNIQUE INDEX uniq_assignment_view (assignment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_view ADD CONSTRAINT FK_A5FB6612D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_view ADD CONSTRAINT FK_A5FB6612CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_view DROP FOREIGN KEY FK_A5FB6612D19302F8');
        $this->addSql('ALTER TABLE assignment_view DROP FOREIGN KEY FK_A5FB6612CB944F1A');
        $this->addSql('DROP TABLE assignment_view');
    }
}
