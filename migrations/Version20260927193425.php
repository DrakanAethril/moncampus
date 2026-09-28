<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Who a MonCampus student is in École Directe when their names differ on the two sides
 * (App\Entity\EcoleDirecteStudentLink): one row per student, and one per École Directe student.
 */
final class Version20260927193425 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ecole_directe_student_link';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ecole_directe_student_link (id INT AUTO_INCREMENT NOT NULL, ecole_directe_id INT NOT NULL, last_name VARCHAR(150) NOT NULL, first_name VARCHAR(150) NOT NULL, linked_at DATETIME NOT NULL, student_id INT NOT NULL, linked_by_id INT DEFAULT NULL, INDEX IDX_86BFAD631AE3CFF3 (linked_by_id), UNIQUE INDEX uniq_ecole_directe_student_link_student (student_id), UNIQUE INDEX uniq_ecole_directe_student_link_ecole_directe (ecole_directe_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ecole_directe_student_link ADD CONSTRAINT FK_86BFAD63CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ecole_directe_student_link ADD CONSTRAINT FK_86BFAD631AE3CFF3 FOREIGN KEY (linked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ecole_directe_student_link DROP FOREIGN KEY FK_86BFAD63CB944F1A');
        $this->addSql('ALTER TABLE ecole_directe_student_link DROP FOREIGN KEY FK_86BFAD631AE3CFF3');
        $this->addSql('DROP TABLE ecole_directe_student_link');
    }
}
