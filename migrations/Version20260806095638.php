<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The assignment creation wizard (design_handoff_creation_travail 2a): what an assignment now
 * announces beyond its title and its deadline.
 *
 * Three families are added to `assignment`: its character and its grading (mandatory/optional,
 * graded/not graded and the visibility of that choice), the submission rules (late allowed, read
 * tracking), and its attachments (matière, group batch targeted, evaluation created in the
 * gradebook). Two child tables carry what an assignment may have several of: the expected
 * productions, each with its format and possibly its own deadline, and the attached supports.
 *
 * Existing assignments are carried over with the meaning they had before, and not with the new
 * screen's default values: mandatory (which they all were), not graded (none has ever given birth to
 * a gradebook evaluation), late submission closed, read tracking open - the opening trace being
 * already written for them by AssignmentView.
 */
final class Version20260806095638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Caractère, notation, productions attendues et supports d'un travail (2a).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assignment_attachment (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, url VARCHAR(2048) DEFAULT NULL, assignment_id INT NOT NULL, INDEX IDX_47FCBD64D19302F8 (assignment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE assignment_expected_production (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, format VARCHAR(20) NOT NULL, due_date DATETIME DEFAULT NULL, position INT NOT NULL, assignment_id INT NOT NULL, INDEX IDX_E29E2842D19302F8 (assignment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assignment_attachment ADD CONSTRAINT FK_47FCBD64D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE assignment_expected_production ADD CONSTRAINT FK_E29E2842D19302F8 FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE CASCADE');
        // The DEFAULTs carry the migration of existing assignments (see the docblock) as much as the
        // value of an insert that would not name them.
        $this->addSql('ALTER TABLE assignment ADD mandatory TINYINT NOT NULL DEFAULT 1, ADD graded TINYINT NOT NULL DEFAULT 0, ADD grading_visible_to_students TINYINT NOT NULL DEFAULT 1, ADD late_submission_allowed TINYINT NOT NULL DEFAULT 0, ADD read_tracking_enabled TINYINT NOT NULL DEFAULT 1, ADD topic_id INT DEFAULT NULL, ADD group_batch_id INT DEFAULT NULL, ADD gradebook_evaluation_id INT DEFAULT NULL');
        // The matière of an assignment already given from a séance is inferred from that séance - it
        // is what carried it, and the assignment takes it as is.
        $this->addSql('UPDATE assignment a INNER JOIN lesson_session s ON s.id = a.lesson_session_id SET a.topic_id = s.topic_id WHERE s.topic_id IS NOT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA1F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAFAAEF205 FOREIGN KEY (group_batch_id) REFERENCES group_batch (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA49C2DDF5 FOREIGN KEY (gradebook_evaluation_id) REFERENCES evaluation (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA1F55203D ON assignment (topic_id)');
        $this->addSql('CREATE INDEX IDX_30C544BAFAAEF205 ON assignment (group_batch_id)');
        $this->addSql('CREATE INDEX IDX_30C544BA49C2DDF5 ON assignment (gradebook_evaluation_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment_attachment DROP FOREIGN KEY FK_47FCBD64D19302F8');
        $this->addSql('ALTER TABLE assignment_expected_production DROP FOREIGN KEY FK_E29E2842D19302F8');
        $this->addSql('DROP TABLE assignment_attachment');
        $this->addSql('DROP TABLE assignment_expected_production');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA1F55203D');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAFAAEF205');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA49C2DDF5');
        $this->addSql('DROP INDEX IDX_30C544BA1F55203D ON assignment');
        $this->addSql('DROP INDEX IDX_30C544BAFAAEF205 ON assignment');
        $this->addSql('DROP INDEX IDX_30C544BA49C2DDF5 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP mandatory, DROP graded, DROP grading_visible_to_students, DROP late_submission_allowed, DROP read_tracking_enabled, DROP topic_id, DROP group_batch_id, DROP gradebook_evaluation_id');
    }
}
