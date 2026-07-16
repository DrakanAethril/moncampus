<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds InternshipStudentEvaluation::$lastEditedBy, mirroring the same tracking-only field
 * already added to internship_tutor_evaluation - lets staff-vs-student edits be told apart
 * internally when staff fill in a self-evaluation on a student's behalf.
 */
final class Version20260716044252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_edited_by_id tracking column to internship_student_evaluation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_student_evaluation ADD last_edited_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28D48D54E8 FOREIGN KEY (last_edited_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_F267DF28D48D54E8 ON internship_student_evaluation (last_edited_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28D48D54E8');
        $this->addSql('DROP INDEX IDX_F267DF28D48D54E8 ON internship_student_evaluation');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP last_edited_by_id');
    }
}
