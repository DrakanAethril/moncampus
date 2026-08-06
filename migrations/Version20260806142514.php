<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The student-facing "Travail à faire" screen (design_handoff_travail_a_faire, screens 3a-3c) and
 * the quiz target the teacher sets alongside it (screen 2a).
 *
 * Three changes. `assignment_dismissal` records a student setting one of their own assignments
 * aside - one row per (assignment, student), no row meaning "not dismissed", the same shape as
 * `assignment_completion`. `assignment.minimum_score_percent` holds the share of correct answers a
 * quiz assignment must reach to count as done; null on every existing row, which keeps their
 * behavior (concluding the quiz is enough). Last, `assignment_submission` gains the expected
 * production it answers, so an assignment spelling out several productions gets one submission per
 * production, each with its own deadline - existing submissions stay production-less, which is what
 * they always were, and the uniqueness constraint widens to let both shapes coexist.
 */
final class Version20260806142514 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Travail ignoré, objectif minimum du quiz et dépôt par production attendue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignment_dismissal (id INT AUTO_INCREMENT NOT NULL, dismissed_at DATETIME NOT NULL, assignment_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_F261691D19302F8 (assignment_id), INDEX IDX_F261691CB944F1A (student_id), UNIQUE INDEX uniq_assignment_dismissal (assignment_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_dismissal ADD CONSTRAINT FK_F261691D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_dismissal ADD CONSTRAINT FK_F261691CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment ADD minimum_score_percent NUMERIC(5, 2) DEFAULT NULL');
        // A deposit belongs to the production it answers: dropping the production drops it too,
        // exactly as dropping the assignment already did.
        $this->addSql('ALTER TABLE assignment_submission ADD expected_production_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment_submission ADD CONSTRAINT FK_E5A63E2C350C6920 FOREIGN KEY (expected_production_id) REFERENCES assignment_expected_production (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E5A63E2C350C6920 ON assignment_submission (expected_production_id)');
        $this->addSql('DROP INDEX uniq_assignment_student ON assignment_submission');
        $this->addSql('CREATE UNIQUE INDEX uniq_assignment_student ON assignment_submission (assignment_id, student_id, expected_production_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_dismissal DROP FOREIGN KEY FK_F261691D19302F8');
        $this->addSql('ALTER TABLE assignment_dismissal DROP FOREIGN KEY FK_F261691CB944F1A');
        $this->addSql('DROP TABLE assignment_dismissal');
        $this->addSql('ALTER TABLE assignment DROP minimum_score_percent');
        // Going back to one submission per (assignment, student) means the extra per-production
        // deposits can no longer be told apart - only the first of each pair survives the revert.
        $this->addSql('DELETE s FROM assignment_submission s INNER JOIN assignment_submission other ON other.assignment_id = s.assignment_id AND other.student_id = s.student_id AND other.id < s.id');
        $this->addSql('ALTER TABLE assignment_submission DROP FOREIGN KEY FK_E5A63E2C350C6920');
        $this->addSql('DROP INDEX IDX_E5A63E2C350C6920 ON assignment_submission');
        $this->addSql('DROP INDEX uniq_assignment_student ON assignment_submission');
        $this->addSql('ALTER TABLE assignment_submission DROP expected_production_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_assignment_student ON assignment_submission (assignment_id, student_id)');
    }
}
