<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sondages - the survey_* tables plus the nullable survey_campaign_id on assignment
 * (design/validated/surveys.md, lot 1).
 */
final class Version20260820213828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sondages : tables survey_* et rattachement au travail à faire';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE survey_answer (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(500) NOT NULL, order_index INT NOT NULL, survey_question_id INT NOT NULL, INDEX IDX_F2D38249A6DF29BA (survey_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_campaign (id INT AUTO_INCREMENT NOT NULL, wave_number INT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, creation_date DATETIME NOT NULL, opens_at DATETIME DEFAULT NULL, closes_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, anonymous TINYINT NOT NULL, results_visible_to_respondents TINYINT NOT NULL, target_frozen_at DATETIME DEFAULT NULL, include_students TINYINT NOT NULL, include_teachers TINYINT NOT NULL, audience_types LONGTEXT DEFAULT NULL, series_id INT NOT NULL, created_by_id INT NOT NULL, INDEX IDX_43E254FA5278319C (series_id), INDEX IDX_43E254FAB03A8386 (created_by_id), UNIQUE INDEX uniq_survey_campaign_series_wave (series_id, wave_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_campaign_program (survey_campaign_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_1B4BCBCF4EC12871 (survey_campaign_id), INDEX IDX_1B4BCBCF3EB8070A (program_id), PRIMARY KEY (survey_campaign_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_campaign_manual_recipient (survey_campaign_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_2899E4724EC12871 (survey_campaign_id), INDEX IDX_2899E472A76ED395 (user_id), PRIMARY KEY (survey_campaign_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_campaign_answer (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(500) NOT NULL, order_index INT NOT NULL, survey_campaign_question_id INT NOT NULL, INDEX IDX_AF4AE1BCF24F0E1C (survey_campaign_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_campaign_question (id INT AUTO_INCREMENT NOT NULL, comparison_key CHAR(40) NOT NULL, type VARCHAR(20) NOT NULL, label LONGTEXT NOT NULL, help_text LONGTEXT DEFAULT NULL, order_index INT NOT NULL, required TINYINT NOT NULL, is_scale TINYINT NOT NULL, min_choices INT DEFAULT NULL, max_choices INT DEFAULT NULL, survey_campaign_id INT NOT NULL, INDEX IDX_577077EA4EC12871 (survey_campaign_id), INDEX idx_survey_campaign_question_key (comparison_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_question (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, label LONGTEXT NOT NULL, help_text LONGTEXT DEFAULT NULL, order_index INT NOT NULL, required TINYINT NOT NULL, is_scale TINYINT NOT NULL, min_choices INT DEFAULT NULL, max_choices INT DEFAULT NULL, survey_template_id INT NOT NULL, INDEX IDX_EA000F69BD22D0BD (survey_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_response (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, display_key CHAR(8) NOT NULL, survey_campaign_id INT NOT NULL, respondent_id INT DEFAULT NULL, INDEX IDX_628C4DDC4EC12871 (survey_campaign_id), INDEX IDX_628C4DDCCE80CD19 (respondent_id), INDEX idx_survey_response_campaign_submitted (survey_campaign_id, submitted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_response_answer (id INT AUTO_INCREMENT NOT NULL, answered_at DATETIME DEFAULT NULL, free_text VARCHAR(2000) DEFAULT NULL, survey_response_id INT NOT NULL, survey_campaign_question_id INT NOT NULL, INDEX IDX_1972BF3E430BF745 (survey_response_id), INDEX IDX_1972BF3EF24F0E1C (survey_campaign_question_id), UNIQUE INDEX uniq_survey_response_answer (survey_response_id, survey_campaign_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_response_selected_answer (id INT AUTO_INCREMENT NOT NULL, order_index INT NOT NULL, survey_response_answer_id INT NOT NULL, survey_campaign_answer_id INT NOT NULL, INDEX IDX_99F6EE7D95E2AFD4 (survey_response_answer_id), INDEX IDX_99F6EE7D7B69A8C8 (survey_campaign_answer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_series (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, owner_id INT NOT NULL, template_id INT DEFAULT NULL, INDEX IDX_121EC9417E3C61F9 (owner_id), INDEX IDX_121EC9415DA0FB8 (template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_target (id INT AUTO_INCREMENT NOT NULL, added_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, reminded_at DATETIME DEFAULT NULL, survey_campaign_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_6E61E7904EC12871 (survey_campaign_id), INDEX IDX_6E61E790A76ED395 (user_id), INDEX idx_survey_target_campaign_responded (survey_campaign_id, responded_at), UNIQUE INDEX uniq_survey_target_campaign_user (survey_campaign_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE survey_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, INDEX IDX_CB9759A47E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE survey_answer ADD CONSTRAINT FK_F2D38249A6DF29BA FOREIGN KEY (survey_question_id) REFERENCES survey_question (id)');
        $this->addSql('ALTER TABLE survey_campaign ADD CONSTRAINT FK_43E254FA5278319C FOREIGN KEY (series_id) REFERENCES survey_series (id)');
        $this->addSql('ALTER TABLE survey_campaign ADD CONSTRAINT FK_43E254FAB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE survey_campaign_program ADD CONSTRAINT FK_1B4BCBCF4EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_campaign_program ADD CONSTRAINT FK_1B4BCBCF3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_campaign_manual_recipient ADD CONSTRAINT FK_2899E4724EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_campaign_manual_recipient ADD CONSTRAINT FK_2899E472A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_campaign_answer ADD CONSTRAINT FK_AF4AE1BCF24F0E1C FOREIGN KEY (survey_campaign_question_id) REFERENCES survey_campaign_question (id)');
        $this->addSql('ALTER TABLE survey_campaign_question ADD CONSTRAINT FK_577077EA4EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id)');
        $this->addSql('ALTER TABLE survey_question ADD CONSTRAINT FK_EA000F69BD22D0BD FOREIGN KEY (survey_template_id) REFERENCES survey_template (id)');
        $this->addSql('ALTER TABLE survey_response ADD CONSTRAINT FK_628C4DDC4EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_response ADD CONSTRAINT FK_628C4DDCCE80CD19 FOREIGN KEY (respondent_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE survey_response_answer ADD CONSTRAINT FK_1972BF3E430BF745 FOREIGN KEY (survey_response_id) REFERENCES survey_response (id)');
        $this->addSql('ALTER TABLE survey_response_answer ADD CONSTRAINT FK_1972BF3EF24F0E1C FOREIGN KEY (survey_campaign_question_id) REFERENCES survey_campaign_question (id)');
        $this->addSql('ALTER TABLE survey_response_selected_answer ADD CONSTRAINT FK_99F6EE7D95E2AFD4 FOREIGN KEY (survey_response_answer_id) REFERENCES survey_response_answer (id)');
        $this->addSql('ALTER TABLE survey_response_selected_answer ADD CONSTRAINT FK_99F6EE7D7B69A8C8 FOREIGN KEY (survey_campaign_answer_id) REFERENCES survey_campaign_answer (id)');
        $this->addSql('ALTER TABLE survey_series ADD CONSTRAINT FK_121EC9417E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE survey_series ADD CONSTRAINT FK_121EC9415DA0FB8 FOREIGN KEY (template_id) REFERENCES survey_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE survey_target ADD CONSTRAINT FK_6E61E7904EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_target ADD CONSTRAINT FK_6E61E790A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_template ADD CONSTRAINT FK_CB9759A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE assignment ADD survey_campaign_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BA4EC12871 FOREIGN KEY (survey_campaign_id) REFERENCES survey_campaign (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA4EC12871 ON assignment (survey_campaign_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey_answer DROP FOREIGN KEY FK_F2D38249A6DF29BA');
        $this->addSql('ALTER TABLE survey_campaign DROP FOREIGN KEY FK_43E254FA5278319C');
        $this->addSql('ALTER TABLE survey_campaign DROP FOREIGN KEY FK_43E254FAB03A8386');
        $this->addSql('ALTER TABLE survey_campaign_program DROP FOREIGN KEY FK_1B4BCBCF4EC12871');
        $this->addSql('ALTER TABLE survey_campaign_program DROP FOREIGN KEY FK_1B4BCBCF3EB8070A');
        $this->addSql('ALTER TABLE survey_campaign_manual_recipient DROP FOREIGN KEY FK_2899E4724EC12871');
        $this->addSql('ALTER TABLE survey_campaign_manual_recipient DROP FOREIGN KEY FK_2899E472A76ED395');
        $this->addSql('ALTER TABLE survey_campaign_answer DROP FOREIGN KEY FK_AF4AE1BCF24F0E1C');
        $this->addSql('ALTER TABLE survey_campaign_question DROP FOREIGN KEY FK_577077EA4EC12871');
        $this->addSql('ALTER TABLE survey_question DROP FOREIGN KEY FK_EA000F69BD22D0BD');
        $this->addSql('ALTER TABLE survey_response DROP FOREIGN KEY FK_628C4DDC4EC12871');
        $this->addSql('ALTER TABLE survey_response DROP FOREIGN KEY FK_628C4DDCCE80CD19');
        $this->addSql('ALTER TABLE survey_response_answer DROP FOREIGN KEY FK_1972BF3E430BF745');
        $this->addSql('ALTER TABLE survey_response_answer DROP FOREIGN KEY FK_1972BF3EF24F0E1C');
        $this->addSql('ALTER TABLE survey_response_selected_answer DROP FOREIGN KEY FK_99F6EE7D95E2AFD4');
        $this->addSql('ALTER TABLE survey_response_selected_answer DROP FOREIGN KEY FK_99F6EE7D7B69A8C8');
        $this->addSql('ALTER TABLE survey_series DROP FOREIGN KEY FK_121EC9417E3C61F9');
        $this->addSql('ALTER TABLE survey_series DROP FOREIGN KEY FK_121EC9415DA0FB8');
        $this->addSql('ALTER TABLE survey_target DROP FOREIGN KEY FK_6E61E7904EC12871');
        $this->addSql('ALTER TABLE survey_target DROP FOREIGN KEY FK_6E61E790A76ED395');
        $this->addSql('ALTER TABLE survey_template DROP FOREIGN KEY FK_CB9759A47E3C61F9');
        $this->addSql('DROP TABLE survey_answer');
        $this->addSql('DROP TABLE survey_campaign');
        $this->addSql('DROP TABLE survey_campaign_program');
        $this->addSql('DROP TABLE survey_campaign_manual_recipient');
        $this->addSql('DROP TABLE survey_campaign_answer');
        $this->addSql('DROP TABLE survey_campaign_question');
        $this->addSql('DROP TABLE survey_question');
        $this->addSql('DROP TABLE survey_response');
        $this->addSql('DROP TABLE survey_response_answer');
        $this->addSql('DROP TABLE survey_response_selected_answer');
        $this->addSql('DROP TABLE survey_series');
        $this->addSql('DROP TABLE survey_target');
        $this->addSql('DROP TABLE survey_template');
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BA4EC12871');
        $this->addSql('DROP INDEX IDX_30C544BA4EC12871 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP survey_campaign_id');
    }
}
