<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707063933 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_behavior_criteria (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, order_index INT NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_7C6B0DC1B03A8386 (created_by_id), INDEX IDX_7C6B0DC1F5A2E305 (inactivated_by_id), INDEX IDX_7C6B0DC1E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_behavior_level (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, level_number INT NOT NULL, behavior_criteria_id INT NOT NULL, INDEX IDX_12C413BBFF9146E (behavior_criteria_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_formation_center (id INT AUTO_INCREMENT NOT NULL, general_info LONGTEXT DEFAULT NULL, director_first_name VARCHAR(255) DEFAULT NULL, director_last_name VARCHAR(255) DEFAULT NULL, director_email VARCHAR(255) DEFAULT NULL, director_phone VARCHAR(30) DEFAULT NULL, campus_director_first_name VARCHAR(255) DEFAULT NULL, campus_director_last_name VARCHAR(255) DEFAULT NULL, campus_director_email VARCHAR(255) DEFAULT NULL, campus_director_phone VARCHAR(30) DEFAULT NULL, alternance_manager_first_name VARCHAR(255) DEFAULT NULL, alternance_manager_last_name VARCHAR(255) DEFAULT NULL, alternance_manager_email VARCHAR(255) DEFAULT NULL, alternance_manager_phone VARCHAR(30) DEFAULT NULL, handicap_referent_first_name VARCHAR(255) DEFAULT NULL, handicap_referent_last_name VARCHAR(255) DEFAULT NULL, handicap_referent_email VARCHAR(255) DEFAULT NULL, handicap_referent_phone VARCHAR(30) DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_EE82A3F6B03A8386 (created_by_id), INDEX IDX_EE82A3F6F5A2E305 (inactivated_by_id), INDEX IDX_EE82A3F6E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_program_info (id INT AUTO_INCREMENT NOT NULL, exam_modality_text LONGTEXT DEFAULT NULL, terms_conditions_pro_text LONGTEXT DEFAULT NULL, terms_conditions_apprentissage_text LONGTEXT DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_9B1F50753EB8070A (program_id), INDEX IDX_9B1F5075B03A8386 (created_by_id), INDEX IDX_9B1F5075F5A2E305 (inactivated_by_id), INDEX IDX_9B1F5075E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_skill_criterion (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, skill_group_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_FADA1FB1BCFCB4B5 (skill_group_id), INDEX IDX_FADA1FB1B03A8386 (created_by_id), INDEX IDX_FADA1FB1F5A2E305 (inactivated_by_id), INDEX IDX_FADA1FB1E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_skill_group (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_50C7F0033EB8070A (program_id), INDEX IDX_50C7F003B03A8386 (created_by_id), INDEX IDX_50C7F003F5A2E305 (inactivated_by_id), INDEX IDX_50C7F003E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_skill_level (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, color VARCHAR(20) NOT NULL, order_index INT NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_A7ED78D5B03A8386 (created_by_id), INDEX IDX_A7ED78D5F5A2E305 (inactivated_by_id), INDEX IDX_A7ED78D5E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_tutor_link (id INT AUTO_INCREMENT NOT NULL, tutor_first_name VARCHAR(255) NOT NULL, tutor_last_name VARCHAR(255) NOT NULL, tutor_email VARCHAR(255) NOT NULL, tutor_phone VARCHAR(30) NOT NULL, company_name VARCHAR(255) NOT NULL, company_address LONGTEXT DEFAULT NULL, contract_start_date DATE NOT NULL, contract_end_date DATE NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, student_id INT NOT NULL, tutor_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_80D957823EB8070A (program_id), INDEX IDX_80D95782CB944F1A (student_id), INDEX IDX_80D95782208F64F1 (tutor_id), INDEX IDX_80D95782B03A8386 (created_by_id), INDEX IDX_80D95782F5A2E305 (inactivated_by_id), INDEX IDX_80D95782E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_behavior_criteria ADD CONSTRAINT FK_7C6B0DC1B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_behavior_criteria ADD CONSTRAINT FK_7C6B0DC1F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_behavior_criteria ADD CONSTRAINT FK_7C6B0DC1E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_behavior_level ADD CONSTRAINT FK_12C413BBFF9146E FOREIGN KEY (behavior_criteria_id) REFERENCES internship_behavior_criteria (id)');
        $this->addSql('ALTER TABLE internship_formation_center ADD CONSTRAINT FK_EE82A3F6B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_formation_center ADD CONSTRAINT FK_EE82A3F6F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_formation_center ADD CONSTRAINT FK_EE82A3F6E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_program_info ADD CONSTRAINT FK_9B1F50753EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_program_info ADD CONSTRAINT FK_9B1F5075B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_program_info ADD CONSTRAINT FK_9B1F5075F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_program_info ADD CONSTRAINT FK_9B1F5075E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_criterion ADD CONSTRAINT FK_FADA1FB1BCFCB4B5 FOREIGN KEY (skill_group_id) REFERENCES internship_skill_group (id)');
        $this->addSql('ALTER TABLE internship_skill_criterion ADD CONSTRAINT FK_FADA1FB1B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_criterion ADD CONSTRAINT FK_FADA1FB1F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_criterion ADD CONSTRAINT FK_FADA1FB1E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_group ADD CONSTRAINT FK_50C7F0033EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_skill_group ADD CONSTRAINT FK_50C7F003B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_group ADD CONSTRAINT FK_50C7F003F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_group ADD CONSTRAINT FK_50C7F003E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_level ADD CONSTRAINT FK_A7ED78D5B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_level ADD CONSTRAINT FK_A7ED78D5F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_skill_level ADD CONSTRAINT FK_A7ED78D5E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D957823EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782208F64F1 FOREIGN KEY (tutor_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_link ADD CONSTRAINT FK_80D95782E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_behavior_criteria DROP FOREIGN KEY FK_7C6B0DC1B03A8386');
        $this->addSql('ALTER TABLE internship_behavior_criteria DROP FOREIGN KEY FK_7C6B0DC1F5A2E305');
        $this->addSql('ALTER TABLE internship_behavior_criteria DROP FOREIGN KEY FK_7C6B0DC1E562D849');
        $this->addSql('ALTER TABLE internship_behavior_level DROP FOREIGN KEY FK_12C413BBFF9146E');
        $this->addSql('ALTER TABLE internship_formation_center DROP FOREIGN KEY FK_EE82A3F6B03A8386');
        $this->addSql('ALTER TABLE internship_formation_center DROP FOREIGN KEY FK_EE82A3F6F5A2E305');
        $this->addSql('ALTER TABLE internship_formation_center DROP FOREIGN KEY FK_EE82A3F6E562D849');
        $this->addSql('ALTER TABLE internship_program_info DROP FOREIGN KEY FK_9B1F50753EB8070A');
        $this->addSql('ALTER TABLE internship_program_info DROP FOREIGN KEY FK_9B1F5075B03A8386');
        $this->addSql('ALTER TABLE internship_program_info DROP FOREIGN KEY FK_9B1F5075F5A2E305');
        $this->addSql('ALTER TABLE internship_program_info DROP FOREIGN KEY FK_9B1F5075E562D849');
        $this->addSql('ALTER TABLE internship_skill_criterion DROP FOREIGN KEY FK_FADA1FB1BCFCB4B5');
        $this->addSql('ALTER TABLE internship_skill_criterion DROP FOREIGN KEY FK_FADA1FB1B03A8386');
        $this->addSql('ALTER TABLE internship_skill_criterion DROP FOREIGN KEY FK_FADA1FB1F5A2E305');
        $this->addSql('ALTER TABLE internship_skill_criterion DROP FOREIGN KEY FK_FADA1FB1E562D849');
        $this->addSql('ALTER TABLE internship_skill_group DROP FOREIGN KEY FK_50C7F0033EB8070A');
        $this->addSql('ALTER TABLE internship_skill_group DROP FOREIGN KEY FK_50C7F003B03A8386');
        $this->addSql('ALTER TABLE internship_skill_group DROP FOREIGN KEY FK_50C7F003F5A2E305');
        $this->addSql('ALTER TABLE internship_skill_group DROP FOREIGN KEY FK_50C7F003E562D849');
        $this->addSql('ALTER TABLE internship_skill_level DROP FOREIGN KEY FK_A7ED78D5B03A8386');
        $this->addSql('ALTER TABLE internship_skill_level DROP FOREIGN KEY FK_A7ED78D5F5A2E305');
        $this->addSql('ALTER TABLE internship_skill_level DROP FOREIGN KEY FK_A7ED78D5E562D849');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D957823EB8070A');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782CB944F1A');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782208F64F1');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782B03A8386');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782F5A2E305');
        $this->addSql('ALTER TABLE internship_tutor_link DROP FOREIGN KEY FK_80D95782E562D849');
        $this->addSql('DROP TABLE internship_behavior_criteria');
        $this->addSql('DROP TABLE internship_behavior_level');
        $this->addSql('DROP TABLE internship_formation_center');
        $this->addSql('DROP TABLE internship_program_info');
        $this->addSql('DROP TABLE internship_skill_criterion');
        $this->addSql('DROP TABLE internship_skill_group');
        $this->addSql('DROP TABLE internship_skill_level');
        $this->addSql('DROP TABLE internship_tutor_link');
    }
}
