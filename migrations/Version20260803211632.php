<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Assignment of the Autoévaluation nature: the student estimates their grade on a gradebook
 * evaluation (design_handoff_carnet_de_notes, PROMPT_MODIFICATIONS §9).
 *
 * Nothing to migrate on the existing data: the two columns added to `assignment` only apply to the
 * new nature and stay null everywhere else.
 */
final class Version20260803211632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Autoévaluation : estimation de l'étudiant sur une évaluation associée à un travail";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE self_assessment (id INT AUTO_INCREMENT NOT NULL, estimated_value DOUBLE PRECISION DEFAULT NULL, updated_at DATETIME NOT NULL, validated_at DATETIME DEFAULT NULL, assignment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_643484DFD19302F8 (assignment_id), INDEX IDX_643484DFCB944F1A (student_id), UNIQUE INDEX uniq_self_assessment_student (assignment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE self_assessment_answer (id INT AUTO_INCREMENT NOT NULL, estimated_points DOUBLE PRECISION DEFAULT NULL, self_assessment_id INT NOT NULL, question_id INT NOT NULL, INDEX IDX_1BA1F6D69ECF1EA3 (self_assessment_id), INDEX IDX_1BA1F6D61E27F6BF (question_id), UNIQUE INDEX uniq_self_assessment_question (self_assessment_id, question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE self_assessment ADD CONSTRAINT FK_643484DFD19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE self_assessment ADD CONSTRAINT FK_643484DFCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE self_assessment_answer ADD CONSTRAINT FK_1BA1F6D69ECF1EA3 FOREIGN KEY (self_assessment_id) REFERENCES self_assessment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE self_assessment_answer ADD CONSTRAINT FK_1BA1F6D61E27F6BF FOREIGN KEY (question_id) REFERENCES evaluation_rubric_question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment ADD self_assessment_feedback VARCHAR(20) DEFAULT NULL, ADD evaluation_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA456C5646 ON assignment (evaluation_id)');
    }

    public function down(Schema $schema): void
    {

        $this->addSql('ALTER TABLE self_assessment DROP FOREIGN KEY FK_643484DFD19302F8');
        $this->addSql('ALTER TABLE self_assessment DROP FOREIGN KEY FK_643484DFCB944F1A');
        $this->addSql('ALTER TABLE self_assessment_answer DROP FOREIGN KEY FK_1BA1F6D69ECF1EA3');
        $this->addSql('ALTER TABLE self_assessment_answer DROP FOREIGN KEY FK_1BA1F6D61E27F6BF');
        $this->addSql('DROP TABLE self_assessment');
        $this->addSql('DROP TABLE self_assessment_answer');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA456C5646');
        $this->addSql('DROP INDEX IDX_30C544BA456C5646 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP self_assessment_feedback, DROP evaluation_id');
    }
}
