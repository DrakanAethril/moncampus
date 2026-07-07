<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707113732 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_student_evaluation (id INT AUTO_INCREMENT NOT NULL, remarks_text LONGTEXT DEFAULT NULL, validation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_F267DF28CB944F1A (student_id), INDEX IDX_F267DF283EB8070A (program_id), INDEX IDX_F267DF28EC8B7ADE (period_id), INDEX IDX_F267DF28B03A8386 (created_by_id), INDEX IDX_F267DF28F5A2E305 (inactivated_by_id), INDEX IDX_F267DF28E562D849 (last_updated_by_id), UNIQUE INDEX internship_student_evaluation_unique (student_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_tutor_evaluation (id INT AUTO_INCREMENT NOT NULL, strengths_text LONGTEXT DEFAULT NULL, weaknesses_text LONGTEXT DEFAULT NULL, goals_text LONGTEXT DEFAULT NULL, remarks_text LONGTEXT DEFAULT NULL, validation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, tutor_link_id INT NOT NULL, period_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_55825EACC55C0FCE (tutor_link_id), INDEX IDX_55825EACEC8B7ADE (period_id), INDEX IDX_55825EACB03A8386 (created_by_id), INDEX IDX_55825EACF5A2E305 (inactivated_by_id), INDEX IDX_55825EACE562D849 (last_updated_by_id), UNIQUE INDEX internship_tutor_evaluation_unique (tutor_link_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_tutor_evaluation_behavior (id INT AUTO_INCREMENT NOT NULL, tutor_evaluation_id INT NOT NULL, behavior_criteria_id INT NOT NULL, behavior_level_id INT DEFAULT NULL, INDEX IDX_149D174353769787 (tutor_evaluation_id), INDEX IDX_149D1743BFF9146E (behavior_criteria_id), INDEX IDX_149D1743B849592C (behavior_level_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE internship_tutor_evaluation_skill (id INT AUTO_INCREMENT NOT NULL, tutor_evaluation_id INT NOT NULL, skill_criterion_id INT NOT NULL, skill_level_id INT DEFAULT NULL, INDEX IDX_10DF3A3F53769787 (tutor_evaluation_id), INDEX IDX_10DF3A3F51AF305 (skill_criterion_id), INDEX IDX_10DF3A3F1D192655 (skill_level_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF283EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28EC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_student_evaluation ADD CONSTRAINT FK_F267DF28E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACC55C0FCE FOREIGN KEY (tutor_link_id) REFERENCES internship_tutor_link (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACEC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation ADD CONSTRAINT FK_55825EACE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior ADD CONSTRAINT FK_149D174353769787 FOREIGN KEY (tutor_evaluation_id) REFERENCES internship_tutor_evaluation (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior ADD CONSTRAINT FK_149D1743BFF9146E FOREIGN KEY (behavior_criteria_id) REFERENCES internship_behavior_criteria (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior ADD CONSTRAINT FK_149D1743B849592C FOREIGN KEY (behavior_level_id) REFERENCES internship_behavior_level (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill ADD CONSTRAINT FK_10DF3A3F53769787 FOREIGN KEY (tutor_evaluation_id) REFERENCES internship_tutor_evaluation (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill ADD CONSTRAINT FK_10DF3A3F51AF305 FOREIGN KEY (skill_criterion_id) REFERENCES internship_skill_criterion (id)');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill ADD CONSTRAINT FK_10DF3A3F1D192655 FOREIGN KEY (skill_level_id) REFERENCES internship_skill_level (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28CB944F1A');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF283EB8070A');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28EC8B7ADE');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28B03A8386');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28F5A2E305');
        $this->addSql('ALTER TABLE internship_student_evaluation DROP FOREIGN KEY FK_F267DF28E562D849');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACC55C0FCE');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACEC8B7ADE');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACB03A8386');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACF5A2E305');
        $this->addSql('ALTER TABLE internship_tutor_evaluation DROP FOREIGN KEY FK_55825EACE562D849');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior DROP FOREIGN KEY FK_149D174353769787');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior DROP FOREIGN KEY FK_149D1743BFF9146E');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_behavior DROP FOREIGN KEY FK_149D1743B849592C');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill DROP FOREIGN KEY FK_10DF3A3F53769787');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill DROP FOREIGN KEY FK_10DF3A3F51AF305');
        $this->addSql('ALTER TABLE internship_tutor_evaluation_skill DROP FOREIGN KEY FK_10DF3A3F1D192655');
        $this->addSql('DROP TABLE internship_student_evaluation');
        $this->addSql('DROP TABLE internship_tutor_evaluation');
        $this->addSql('DROP TABLE internship_tutor_evaluation_behavior');
        $this->addSql('DROP TABLE internship_tutor_evaluation_skill');
    }
}
