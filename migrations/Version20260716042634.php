<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds internship_evaluation_period (see App\Entity\InternshipEvaluationPeriod's docblock for why
 * Period/PeriodType/PeriodGroup - the alternance-calendar structure - were never fit for driving
 * Livret Alternant evaluations: staff had no way to flag which Period represented company time,
 * so the tutor's own evaluation screen ended up asking for an evaluation against every active
 * Period, vacations included) and retargets InternshipTutorEvaluation/InternshipStudentEvaluation/
 * InternshipTeamEvaluation's period FK onto it. Also adds InternshipTutorEvaluation::$lastEditedBy
 * (tracking only, staff-vs-tutor edits - never shown on the booklet/PDF).
 *
 * No existing InternshipTutorEvaluation/InternshipStudentEvaluation/InternshipTeamEvaluation rows
 * are preserved across the retarget - confirmed with the project owner that only test/seed data
 * exists (nothing in production yet), so this migration deletes them outright rather than
 * synthesizing InternshipEvaluationPeriod rows to backfill onto.
 */
final class Version20260716042634 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add InternshipEvaluationPeriod and retarget tutor/student/team Livret Alternant evaluations onto it instead of the alternance-calendar Period; wipe existing (test-only) evaluation data';
    }

    public function up(Schema $schema): void
    {
        // Existing rows reference the old `period` FK, about to be dropped in favor of
        // `internship_evaluation_period` - no prod data exists yet (confirmed), so these are
        // wiped rather than migrated. Children first to satisfy FK constraints.
        $this->addSql('DELETE FROM internship_tutor_evaluation_skill');
        $this->addSql('DELETE FROM internship_tutor_evaluation_behavior');
        $this->addSql('DELETE FROM internship_tutor_evaluation');
        $this->addSql('DELETE FROM internship_student_evaluation');
        $this->addSql('DELETE FROM internship_team_evaluation');

        $this->addSql('CREATE TABLE internship_evaluation_period (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_224BAFF23EB8070A (program_id), INDEX IDX_224BAFF2B03A8386 (created_by_id), INDEX IDX_224BAFF2F5A2E305 (inactivated_by_id), INDEX IDX_224BAFF2E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_evaluation_period ADD CONSTRAINT FK_224BAFF23EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_evaluation_period ADD CONSTRAINT FK_224BAFF2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_evaluation_period ADD CONSTRAINT FK_224BAFF2F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_evaluation_period ADD CONSTRAINT FK_224BAFF2E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY `FK_F267DF28EC8B7ADE`');
        $this->addSql('DROP INDEX internship_student_evaluation_unique ON internship_student_evaluation');
        $this->addSql('DROP INDEX IDX_F267DF28EC8B7ADE ON internship_student_evaluation');
        $this->addSql('ALTER TABLE internship_student_evaluation CHANGE period_id evaluation_period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF283E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id)');
        $this->addSql('CREATE INDEX IDX_F267DF283E8BB15A ON internship_student_evaluation (evaluation_period_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_student_evaluation_unique ON internship_student_evaluation (student_id, evaluation_period_id)');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DEC8B7ADE`');
        $this->addSql('DROP INDEX internship_team_evaluation_unique ON internship_team_evaluation');
        $this->addSql('DROP INDEX IDX_F853D12DEC8B7ADE ON internship_team_evaluation');
        $this->addSql('ALTER TABLE internship_team_evaluation CHANGE period_id evaluation_period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12D3E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id)');
        $this->addSql('CREATE INDEX IDX_F853D12D3E8BB15A ON internship_team_evaluation (evaluation_period_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_team_evaluation_unique ON internship_team_evaluation (student_id, evaluation_period_id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY `FK_55825EACEC8B7ADE`');
        $this->addSql('DROP INDEX internship_tutor_evaluation_unique ON internship_tutor_evaluation');
        $this->addSql('DROP INDEX IDX_55825EACEC8B7ADE ON internship_tutor_evaluation');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD last_edited_by_id INT DEFAULT NULL, CHANGE period_id evaluation_period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EAC3E8BB15A FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACD48D54E8 FOREIGN KEY (last_edited_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_55825EAC3E8BB15A ON internship_tutor_evaluation (evaluation_period_id)');
        $this->addSql('CREATE INDEX IDX_55825EACD48D54E8 ON internship_tutor_evaluation (last_edited_by_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_tutor_evaluation_unique ON internship_tutor_evaluation (tutor_link_id, evaluation_period_id)');
    }

    public function down(Schema $schema): void
    {
        // Lossy by nature - the deleted evaluation data from up() is gone for good, this only
        // restores the schema shape.
        $this->addSql('ALTER TABLE internship_evaluation_period DROP FOREIGN KEY FK_224BAFF23EB8070A');
        $this->addSql('ALTER TABLE internship_evaluation_period DROP FOREIGN KEY FK_224BAFF2B03A8386');
        $this->addSql('ALTER TABLE internship_evaluation_period DROP FOREIGN KEY FK_224BAFF2F5A2E305');
        $this->addSql('ALTER TABLE internship_evaluation_period DROP FOREIGN KEY FK_224BAFF2E562D849');
        $this->addSql('DROP TABLE internship_evaluation_period');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF283E8BB15A');
        $this->addSql('DROP INDEX IDX_F267DF283E8BB15A ON internship_student_evaluation');
        $this->addSql('DROP INDEX internship_student_evaluation_unique ON internship_student_evaluation');
        $this->addSql('ALTER TABLE internship_student_evaluation CHANGE evaluation_period_id period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT `FK_F267DF28EC8B7ADE` FOREIGN KEY (period_id) REFERENCES period (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_F267DF28EC8B7ADE ON internship_student_evaluation (period_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_student_evaluation_unique ON internship_student_evaluation (student_id, period_id)');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12D3E8BB15A');
        $this->addSql('DROP INDEX IDX_F853D12D3E8BB15A ON internship_team_evaluation');
        $this->addSql('DROP INDEX internship_team_evaluation_unique ON internship_team_evaluation');
        $this->addSql('ALTER TABLE internship_team_evaluation CHANGE evaluation_period_id period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DEC8B7ADE` FOREIGN KEY (period_id) REFERENCES period (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_F853D12DEC8B7ADE ON internship_team_evaluation (period_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_team_evaluation_unique ON internship_team_evaluation (student_id, period_id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EAC3E8BB15A');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACD48D54E8');
        $this->addSql('DROP INDEX IDX_55825EAC3E8BB15A ON internship_tutor_evaluation');
        $this->addSql('DROP INDEX IDX_55825EACD48D54E8 ON internship_tutor_evaluation');
        $this->addSql('DROP INDEX internship_tutor_evaluation_unique ON internship_tutor_evaluation');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP last_edited_by_id, CHANGE evaluation_period_id period_id INT NOT NULL');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT `FK_55825EACEC8B7ADE` FOREIGN KEY (period_id) REFERENCES period (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_55825EACEC8B7ADE ON internship_tutor_evaluation (period_id)');
        $this->addSql('CREATE UNIQUE INDEX internship_tutor_evaluation_unique ON internship_tutor_evaluation (tutor_link_id, period_id)');
    }
}
