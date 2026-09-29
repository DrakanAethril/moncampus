<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portfolio des réalisations professionnelles (design/validated/portfolio.md): the référentiels,
 * the portfolios, their réalisations, E6 fiches, evidence, decisions, deposits, the France
 * compétences import requests, the designated validateurs, and the six columns on program.
 */
final class Version20260929162450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio E5/E6 : référentiels, portfolios, réalisations, fiches E6, dépôts, validateurs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE portfolio (id INT AUTO_INCREMENT NOT NULL, candidate_number VARCHAR(30) DEFAULT NULL, external_url VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, referential_id INT NOT NULL, INDEX IDX_A9ED1062CB944F1A (student_id), INDEX IDX_A9ED106275DD17F9 (referential_id), UNIQUE INDEX portfolio_student_referential_unique (student_id, referential_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_achievement (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, setting VARCHAR(20) NOT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, organisation VARCHAR(255) DEFAULT NULL, place VARCHAR(255) DEFAULT NULL, teamwork TINYINT DEFAULT 0 NOT NULL, team_note LONGTEXT DEFAULT NULL, description_html LONGTEXT DEFAULT NULL, state VARCHAR(20) NOT NULL, revision INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, portfolio_id INT NOT NULL, requested_reviewer_id INT DEFAULT NULL, source_submission_id INT DEFAULT NULL, INDEX IDX_284A4925B96B5643 (portfolio_id), INDEX IDX_284A492565F32A5D (requested_reviewer_id), INDEX IDX_284A492524919851 (source_submission_id), INDEX portfolio_achievement_state_idx (state, submitted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_claim (id INT AUTO_INCREMENT NOT NULL, justification LONGTEXT NOT NULL, state VARCHAR(20) NOT NULL, decision_comment LONGTEXT DEFAULT NULL, achievement_id INT NOT NULL, competency_id INT NOT NULL, INDEX IDX_FA31F462B3EC99FE (achievement_id), INDEX IDX_FA31F462FB9F58C (competency_id), UNIQUE INDEX portfolio_claim_unique (achievement_id, competency_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_deposit (id INT AUTO_INCREMENT NOT NULL, exam VARCHAR(5) NOT NULL, session INT DEFAULT NULL, deposited_at DATETIME NOT NULL, deadline DATETIME DEFAULT NULL, late TINYINT DEFAULT 0 NOT NULL, snapshot JSON NOT NULL, xlsx_key VARCHAR(255) DEFAULT NULL, pdf_key VARCHAR(255) DEFAULT NULL, state VARCHAR(20) NOT NULL, checklist JSON NOT NULL, check_comment LONGTEXT DEFAULT NULL, checked_at DATETIME DEFAULT NULL, portfolio_id INT NOT NULL, program_id INT NOT NULL, deposited_by_id INT NOT NULL, checked_by_id INT DEFAULT NULL, INDEX IDX_C36EB2F7B96B5643 (portfolio_id), INDEX IDX_C36EB2F73EB8070A (program_id), INDEX IDX_C36EB2F7664F6F5D (deposited_by_id), INDEX IDX_C36EB2F72199DB86 (checked_by_id), INDEX portfolio_deposit_exam_idx (portfolio_id, exam, deposited_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_evidence (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, label VARCHAR(255) NOT NULL, file_key VARCHAR(255) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(150) DEFAULT NULL, size_bytes INT DEFAULT NULL, url VARCHAR(1000) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, achievement_id INT DEFAULT NULL, showcase_id INT DEFAULT NULL, portfolio_id INT DEFAULT NULL, submission_id INT DEFAULT NULL, INDEX IDX_70EB0D88B3EC99FE (achievement_id), INDEX IDX_70EB0D88B9441CED (showcase_id), INDEX IDX_70EB0D88B96B5643 (portfolio_id), INDEX IDX_70EB0D88E1FD4933 (submission_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_review (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, decided_at DATETIME NOT NULL, decision VARCHAR(20) NOT NULL, comment LONGTEXT DEFAULT NULL, claims JSON NOT NULL, snapshot JSON NOT NULL, environment_compliant TINYINT DEFAULT NULL, achievement_id INT DEFAULT NULL, showcase_id INT DEFAULT NULL, reviewer_id INT NOT NULL, INDEX IDX_7FA86CF3B3EC99FE (achievement_id), INDEX IDX_7FA86CF3B9441CED (showcase_id), INDEX IDX_7FA86CF370574616 (reviewer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_showcase (id INT AUTO_INCREMENT NOT NULL, number SMALLINT NOT NULL, achievement_revision INT NOT NULL, conditions_html LONGTEXT DEFAULT NULL, resources_html LONGTEXT DEFAULT NULL, access_html LONGTEXT DEFAULT NULL, description_html LONGTEXT DEFAULT NULL, state VARCHAR(20) NOT NULL, revision INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, validated_at DATETIME DEFAULT NULL, portfolio_id INT NOT NULL, achievement_id INT NOT NULL, requested_reviewer_id INT DEFAULT NULL, INDEX IDX_6832D648B96B5643 (portfolio_id), INDEX IDX_6832D648B3EC99FE (achievement_id), INDEX IDX_6832D64865F32A5D (requested_reviewer_id), UNIQUE INDEX portfolio_showcase_number_unique (portfolio_id, number), UNIQUE INDEX portfolio_showcase_achievement_unique (portfolio_id, achievement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_validator (id INT AUTO_INCREMENT NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, program_id INT NOT NULL, option_id INT NOT NULL, teacher_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, INDEX IDX_40A01E43EB8070A (program_id), INDEX IDX_40A01E4A7C41D6F (option_id), INDEX IDX_40A01E441807E1D (teacher_id), INDEX IDX_40A01E4B03A8386 (created_by_id), INDEX IDX_40A01E4F5A2E305 (inactivated_by_id), UNIQUE INDEX portfolio_validator_unique (program_id, option_id, teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE referential (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, label VARCHAR(120) NOT NULL, version VARCHAR(60) NOT NULL, rncp_code VARCHAR(20) DEFAULT NULL, rncp_title LONGTEXT DEFAULT NULL, level VARCHAR(60) DEFAULT NULL, certifier VARCHAR(255) DEFAULT NULL, registered_from DATE DEFAULT NULL, registered_until DATE DEFAULT NULL, replaces_rncp_code VARCHAR(20) DEFAULT NULL, rncp_source_date DATE DEFAULT NULL, synthesis_model VARCHAR(20) NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, replaces_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_D300E52D9F193203 (replaces_id), INDEX IDX_D300E52DB03A8386 (created_by_id), INDEX IDX_D300E52DF5A2E305 (inactivated_by_id), INDEX IDX_D300E52DE562D849 (last_updated_by_id), UNIQUE INDEX referential_code_version_unique (code, version), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE referential_block (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, rncp_code VARCHAR(30) DEFAULT NULL, label VARCHAR(500) NOT NULL, position INT NOT NULL, role VARCHAR(20) NOT NULL, referential_id INT NOT NULL, INDEX IDX_DDEAAE9075DD17F9 (referential_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE referential_block_option (referential_block_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_D9315FABEB09E71C (referential_block_id), INDEX IDX_D9315FABA7C41D6F (option_id), PRIMARY KEY (referential_block_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE referential_competency (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(20) NOT NULL, label VARCHAR(500) NOT NULL, short_label VARCHAR(40) DEFAULT NULL, skills JSON NOT NULL, position INT NOT NULL, block_id INT NOT NULL, INDEX IDX_F7CFB152E9ED820C (block_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE referential_template (id INT AUTO_INCREMENT NOT NULL, session INT NOT NULL, kind VARCHAR(30) NOT NULL, file_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, anchors JSON NOT NULL, in_service_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, referential_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_95847A9375DD17F9 (referential_id), INDEX IDX_95847A93B03A8386 (created_by_id), INDEX IDX_95847A93F5A2E305 (inactivated_by_id), INDEX IDX_95847A93E562D849 (last_updated_by_id), UNIQUE INDEX referential_template_unique (referential_id, session, kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rncp_import (id INT AUTO_INCREMENT NOT NULL, rncp_code VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, source_file VARCHAR(255) DEFAULT NULL, source_date DATE DEFAULT NULL, payload JSON NOT NULL, error LONGTEXT DEFAULT NULL, applied_at DATETIME DEFAULT NULL, requested_by_id INT NOT NULL, referential_id INT DEFAULT NULL, INDEX IDX_3248B40C4DA1E751 (requested_by_id), INDEX IDX_3248B40C75DD17F9 (referential_id), INDEX rncp_import_state_idx (state, requested_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE portfolio ADD CONSTRAINT FK_A9ED1062CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio ADD CONSTRAINT FK_A9ED106275DD17F9 FOREIGN KEY (referential_id) REFERENCES referential (id)');
        $this->addSql('ALTER TABLE portfolio_achievement ADD CONSTRAINT FK_284A4925B96B5643 FOREIGN KEY (portfolio_id) REFERENCES portfolio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_achievement ADD CONSTRAINT FK_284A492565F32A5D FOREIGN KEY (requested_reviewer_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE portfolio_achievement ADD CONSTRAINT FK_284A492524919851 FOREIGN KEY (source_submission_id) REFERENCES assignment_submission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE portfolio_claim ADD CONSTRAINT FK_FA31F462B3EC99FE FOREIGN KEY (achievement_id) REFERENCES portfolio_achievement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_claim ADD CONSTRAINT FK_FA31F462FB9F58C FOREIGN KEY (competency_id) REFERENCES referential_competency (id)');
        $this->addSql('ALTER TABLE portfolio_deposit ADD CONSTRAINT FK_C36EB2F7B96B5643 FOREIGN KEY (portfolio_id) REFERENCES portfolio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_deposit ADD CONSTRAINT FK_C36EB2F73EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE portfolio_deposit ADD CONSTRAINT FK_C36EB2F7664F6F5D FOREIGN KEY (deposited_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio_deposit ADD CONSTRAINT FK_C36EB2F72199DB86 FOREIGN KEY (checked_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio_evidence ADD CONSTRAINT FK_70EB0D88B3EC99FE FOREIGN KEY (achievement_id) REFERENCES portfolio_achievement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_evidence ADD CONSTRAINT FK_70EB0D88B9441CED FOREIGN KEY (showcase_id) REFERENCES portfolio_showcase (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_evidence ADD CONSTRAINT FK_70EB0D88B96B5643 FOREIGN KEY (portfolio_id) REFERENCES portfolio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_evidence ADD CONSTRAINT FK_70EB0D88E1FD4933 FOREIGN KEY (submission_id) REFERENCES assignment_submission (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE portfolio_review ADD CONSTRAINT FK_7FA86CF3B3EC99FE FOREIGN KEY (achievement_id) REFERENCES portfolio_achievement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_review ADD CONSTRAINT FK_7FA86CF3B9441CED FOREIGN KEY (showcase_id) REFERENCES portfolio_showcase (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_review ADD CONSTRAINT FK_7FA86CF370574616 FOREIGN KEY (reviewer_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio_showcase ADD CONSTRAINT FK_6832D648B96B5643 FOREIGN KEY (portfolio_id) REFERENCES portfolio (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_showcase ADD CONSTRAINT FK_6832D648B3EC99FE FOREIGN KEY (achievement_id) REFERENCES portfolio_achievement (id)');
        $this->addSql('ALTER TABLE portfolio_showcase ADD CONSTRAINT FK_6832D64865F32A5D FOREIGN KEY (requested_reviewer_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE portfolio_validator ADD CONSTRAINT FK_40A01E43EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_validator ADD CONSTRAINT FK_40A01E4A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');
        $this->addSql('ALTER TABLE portfolio_validator ADD CONSTRAINT FK_40A01E441807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio_validator ADD CONSTRAINT FK_40A01E4B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE portfolio_validator ADD CONSTRAINT FK_40A01E4F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential ADD CONSTRAINT FK_D300E52D9F193203 FOREIGN KEY (replaces_id) REFERENCES referential (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE referential ADD CONSTRAINT FK_D300E52DB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential ADD CONSTRAINT FK_D300E52DF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential ADD CONSTRAINT FK_D300E52DE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential_block ADD CONSTRAINT FK_DDEAAE9075DD17F9 FOREIGN KEY (referential_id) REFERENCES referential (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE referential_block_option ADD CONSTRAINT FK_D9315FABEB09E71C FOREIGN KEY (referential_block_id) REFERENCES referential_block (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE referential_block_option ADD CONSTRAINT FK_D9315FABA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE referential_competency ADD CONSTRAINT FK_F7CFB152E9ED820C FOREIGN KEY (block_id) REFERENCES referential_block (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE referential_template ADD CONSTRAINT FK_95847A9375DD17F9 FOREIGN KEY (referential_id) REFERENCES referential (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE referential_template ADD CONSTRAINT FK_95847A93B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential_template ADD CONSTRAINT FK_95847A93F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE referential_template ADD CONSTRAINT FK_95847A93E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE rncp_import ADD CONSTRAINT FK_3248B40C4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE rncp_import ADD CONSTRAINT FK_3248B40C75DD17F9 FOREIGN KEY (referential_id) REFERENCES referential (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE program ADD portfolio_enabled TINYINT DEFAULT 0 NOT NULL, ADD portfolio_cursus_year SMALLINT DEFAULT NULL, ADD portfolio_e5_deadline DATETIME DEFAULT NULL, ADD portfolio_e6_deadline DATETIME DEFAULT NULL, ADD portfolio_exam_session INT DEFAULT NULL, ADD portfolio_referential_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784294493D6 FOREIGN KEY (portfolio_referential_id) REFERENCES referential (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_92ED7784294493D6 ON program (portfolio_referential_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio DROP FOREIGN KEY FK_A9ED1062CB944F1A');
        $this->addSql('ALTER TABLE portfolio DROP FOREIGN KEY FK_A9ED106275DD17F9');
        $this->addSql('ALTER TABLE portfolio_achievement DROP FOREIGN KEY FK_284A4925B96B5643');
        $this->addSql('ALTER TABLE portfolio_achievement DROP FOREIGN KEY FK_284A492565F32A5D');
        $this->addSql('ALTER TABLE portfolio_achievement DROP FOREIGN KEY FK_284A492524919851');
        $this->addSql('ALTER TABLE portfolio_claim DROP FOREIGN KEY FK_FA31F462B3EC99FE');
        $this->addSql('ALTER TABLE portfolio_claim DROP FOREIGN KEY FK_FA31F462FB9F58C');
        $this->addSql('ALTER TABLE portfolio_deposit DROP FOREIGN KEY FK_C36EB2F7B96B5643');
        $this->addSql('ALTER TABLE portfolio_deposit DROP FOREIGN KEY FK_C36EB2F73EB8070A');
        $this->addSql('ALTER TABLE portfolio_deposit DROP FOREIGN KEY FK_C36EB2F7664F6F5D');
        $this->addSql('ALTER TABLE portfolio_deposit DROP FOREIGN KEY FK_C36EB2F72199DB86');
        $this->addSql('ALTER TABLE portfolio_evidence DROP FOREIGN KEY FK_70EB0D88B3EC99FE');
        $this->addSql('ALTER TABLE portfolio_evidence DROP FOREIGN KEY FK_70EB0D88B9441CED');
        $this->addSql('ALTER TABLE portfolio_evidence DROP FOREIGN KEY FK_70EB0D88B96B5643');
        $this->addSql('ALTER TABLE portfolio_evidence DROP FOREIGN KEY FK_70EB0D88E1FD4933');
        $this->addSql('ALTER TABLE portfolio_review DROP FOREIGN KEY FK_7FA86CF3B3EC99FE');
        $this->addSql('ALTER TABLE portfolio_review DROP FOREIGN KEY FK_7FA86CF3B9441CED');
        $this->addSql('ALTER TABLE portfolio_review DROP FOREIGN KEY FK_7FA86CF370574616');
        $this->addSql('ALTER TABLE portfolio_showcase DROP FOREIGN KEY FK_6832D648B96B5643');
        $this->addSql('ALTER TABLE portfolio_showcase DROP FOREIGN KEY FK_6832D648B3EC99FE');
        $this->addSql('ALTER TABLE portfolio_showcase DROP FOREIGN KEY FK_6832D64865F32A5D');
        $this->addSql('ALTER TABLE portfolio_validator DROP FOREIGN KEY FK_40A01E43EB8070A');
        $this->addSql('ALTER TABLE portfolio_validator DROP FOREIGN KEY FK_40A01E4A7C41D6F');
        $this->addSql('ALTER TABLE portfolio_validator DROP FOREIGN KEY FK_40A01E441807E1D');
        $this->addSql('ALTER TABLE portfolio_validator DROP FOREIGN KEY FK_40A01E4B03A8386');
        $this->addSql('ALTER TABLE portfolio_validator DROP FOREIGN KEY FK_40A01E4F5A2E305');
        $this->addSql('ALTER TABLE referential DROP FOREIGN KEY FK_D300E52D9F193203');
        $this->addSql('ALTER TABLE referential DROP FOREIGN KEY FK_D300E52DB03A8386');
        $this->addSql('ALTER TABLE referential DROP FOREIGN KEY FK_D300E52DF5A2E305');
        $this->addSql('ALTER TABLE referential DROP FOREIGN KEY FK_D300E52DE562D849');
        $this->addSql('ALTER TABLE referential_block DROP FOREIGN KEY FK_DDEAAE9075DD17F9');
        $this->addSql('ALTER TABLE referential_block_option DROP FOREIGN KEY FK_D9315FABEB09E71C');
        $this->addSql('ALTER TABLE referential_block_option DROP FOREIGN KEY FK_D9315FABA7C41D6F');
        $this->addSql('ALTER TABLE referential_competency DROP FOREIGN KEY FK_F7CFB152E9ED820C');
        $this->addSql('ALTER TABLE referential_template DROP FOREIGN KEY FK_95847A9375DD17F9');
        $this->addSql('ALTER TABLE referential_template DROP FOREIGN KEY FK_95847A93B03A8386');
        $this->addSql('ALTER TABLE referential_template DROP FOREIGN KEY FK_95847A93F5A2E305');
        $this->addSql('ALTER TABLE referential_template DROP FOREIGN KEY FK_95847A93E562D849');
        $this->addSql('ALTER TABLE rncp_import DROP FOREIGN KEY FK_3248B40C4DA1E751');
        $this->addSql('ALTER TABLE rncp_import DROP FOREIGN KEY FK_3248B40C75DD17F9');
        $this->addSql('DROP TABLE portfolio');
        $this->addSql('DROP TABLE portfolio_achievement');
        $this->addSql('DROP TABLE portfolio_claim');
        $this->addSql('DROP TABLE portfolio_deposit');
        $this->addSql('DROP TABLE portfolio_evidence');
        $this->addSql('DROP TABLE portfolio_review');
        $this->addSql('DROP TABLE portfolio_showcase');
        $this->addSql('DROP TABLE portfolio_validator');
        $this->addSql('DROP TABLE referential');
        $this->addSql('DROP TABLE referential_block');
        $this->addSql('DROP TABLE referential_block_option');
        $this->addSql('DROP TABLE referential_competency');
        $this->addSql('DROP TABLE referential_template');
        $this->addSql('DROP TABLE rncp_import');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784294493D6');
        $this->addSql('DROP INDEX IDX_92ED7784294493D6 ON program');
        $this->addSql('ALTER TABLE program DROP portfolio_enabled, DROP portfolio_cursus_year, DROP portfolio_e5_deadline, DROP portfolio_e6_deadline, DROP portfolio_exam_session, DROP portfolio_referential_id');
    }
}
