<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the standalone TSF-export Skill entity (and its skill_option table) entirely, then
 * renames skill_criterion to skill (table rename only, content preserved) so the evaluable item
 * within a SkillGroup is simply called "Skill" - resolving the confusing overlap where two
 * unrelated concepts were both labelled "Compétence" in French. Also drops
 * program.topic_skill_management_enabled, the feature flag that only ever gated the now-removed
 * standalone Skill/TSF tab.
 */
final class Version20260711170918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the standalone TSF Skill entity (skill, skill_option) and program.topic_skill_management_enabled; rename skill_criterion to skill and internship_tutor_evaluation_skill.skill_criterion_id to skill_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill_option DROP FOREIGN KEY FK_75CC54A45585C142');
        $this->addSql('ALTER TABLE skill_option DROP FOREIGN KEY FK_75CC54A4A7C41D6F');
        $this->addSql('DROP TABLE skill_option');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE4773EB8070A');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE47741807E1D');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477B03A8386');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477E562D849');
        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE477F5A2E305');
        $this->addSql('DROP TABLE skill');
        $this->addSql('RENAME TABLE skill_criterion TO skill');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill CHANGE skill_criterion_id skill_id INT NOT NULL');
        $this->addSql('ALTER TABLE program DROP topic_skill_management_enabled');
        // Index names are derived from the table name, so the rename above leaves them stamped
        // with the old skill_criterion hash - realign them with what Doctrine now generates for
        // the skill table so schema:validate stays clean.
        $this->addSql('ALTER TABLE skill RENAME INDEX idx_2f73192cbcfcb4b5 TO IDX_5E3DE477BCFCB4B5');
        $this->addSql('ALTER TABLE skill RENAME INDEX idx_2f73192cb03a8386 TO IDX_5E3DE477B03A8386');
        $this->addSql('ALTER TABLE skill RENAME INDEX idx_2f73192cf5a2e305 TO IDX_5E3DE477F5A2E305');
        $this->addSql('ALTER TABLE skill RENAME INDEX idx_2f73192ce562d849 TO IDX_5E3DE477E562D849');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill RENAME INDEX idx_10df3a3f51af305 TO IDX_10DF3A3F5585C142');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill RENAME INDEX IDX_10DF3A3F5585C142 TO idx_10df3a3f51af305');
        $this->addSql('ALTER TABLE skill RENAME INDEX IDX_5E3DE477E562D849 TO idx_2f73192ce562d849');
        $this->addSql('ALTER TABLE skill RENAME INDEX IDX_5E3DE477F5A2E305 TO idx_2f73192cf5a2e305');
        $this->addSql('ALTER TABLE skill RENAME INDEX IDX_5E3DE477B03A8386 TO idx_2f73192cb03a8386');
        $this->addSql('ALTER TABLE skill RENAME INDEX IDX_5E3DE477BCFCB4B5 TO idx_2f73192cbcfcb4b5');
        $this->addSql('ALTER TABLE program ADD topic_skill_management_enabled TINYINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill CHANGE skill_id skill_criterion_id INT NOT NULL');
        $this->addSql('RENAME TABLE skill TO skill_criterion');
        $this->addSql(<<<'SQL'
            CREATE TABLE skill (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              short_name VARCHAR(255) DEFAULT NULL,
              description LONGTEXT DEFAULT NULL,
              professional LONGTEXT DEFAULT NULL,
              knowledge LONGTEXT DEFAULT NULL,
              performance LONGTEXT DEFAULT NULL,
              evaluation_modality LONGTEXT DEFAULT NULL,
              volume DOUBLE PRECISION DEFAULT NULL,
              period VARCHAR(255) DEFAULT NULL,
              visible_in_booklet TINYINT NOT NULL DEFAULT 1,
              visible_in_program TINYINT NOT NULL DEFAULT 1,
              creation_date DATETIME NOT NULL,
              inactive_date DATETIME DEFAULT NULL,
              last_updated_date DATETIME DEFAULT NULL,
              program_id INT NOT NULL,
              teacher_id INT DEFAULT NULL,
              created_by_id INT NOT NULL,
              inactivated_by_id INT DEFAULT NULL,
              last_updated_by_id INT DEFAULT NULL,
              INDEX IDX_5E3DE4773EB8070A (program_id),
              INDEX IDX_5E3DE47741807E1D (teacher_id),
              INDEX IDX_5E3DE477B03A8386 (created_by_id),
              INDEX IDX_5E3DE477F5A2E305 (inactivated_by_id),
              INDEX IDX_5E3DE477E562D849 (last_updated_by_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE4773EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE47741807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE477E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE TABLE skill_option (skill_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_75CC54A45585C142 (skill_id), INDEX IDX_75CC54A4A7C41D6F (option_id), PRIMARY KEY (skill_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE skill_option ADD CONSTRAINT FK_75CC54A45585C142 FOREIGN KEY (skill_id) REFERENCES skill (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE skill_option ADD CONSTRAINT FK_75CC54A4A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }
}
