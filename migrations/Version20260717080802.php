<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717080802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiz_instance/quiz_instance_question/quiz_instance_answer (Générateur de quiz - launch snapshot).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quiz_instance (id INT AUTO_INCREMENT NOT NULL, creation_date DATETIME NOT NULL, name VARCHAR(255) NOT NULL, subject VARCHAR(255) DEFAULT NULL, mode VARCHAR(20) NOT NULL, opens_at DATETIME DEFAULT NULL, closes_at DATETIME DEFAULT NULL, question_count INT NOT NULL, difficulty_facile_percent INT NOT NULL, difficulty_moyen_percent INT NOT NULL, difficulty_difficile_percent INT NOT NULL, difficulty_facile_count INT NOT NULL, difficulty_moyen_count INT NOT NULL, difficulty_difficile_count INT NOT NULL, same_questions_for_all TINYINT DEFAULT 1 NOT NULL, question_order_per_student TINYINT DEFAULT 1 NOT NULL, answer_order_per_student TINYINT DEFAULT 0 NOT NULL, seconds_per_question INT DEFAULT NULL, global_time_minutes INT DEFAULT NULL, scoring VARCHAR(20) NOT NULL, score_visible_immediately TINYINT DEFAULT 1 NOT NULL, program_id INT NOT NULL, source_template_id INT DEFAULT NULL, created_by_id INT NOT NULL, INDEX IDX_94F4489B3EB8070A (program_id), INDEX IDX_94F4489B39A55F18 (source_template_id), INDEX IDX_94F4489BB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_instance_answer (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(500) NOT NULL, is_correct TINYINT DEFAULT 0 NOT NULL, order_index INT NOT NULL, instance_question_id INT NOT NULL, INDEX IDX_A2BC870FF385AA49 (instance_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_instance_question (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, difficulty VARCHAR(20) DEFAULT NULL, label LONGTEXT NOT NULL, image_storage_key VARCHAR(255) DEFAULT NULL, order_index INT NOT NULL, quiz_instance_id INT NOT NULL, INDEX IDX_3C49430A157761BD (quiz_instance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_instance ADD CONSTRAINT FK_94F4489B3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE quiz_instance ADD CONSTRAINT FK_94F4489B39A55F18 FOREIGN KEY (source_template_id) REFERENCES quiz_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_instance ADD CONSTRAINT FK_94F4489BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_instance_answer ADD CONSTRAINT FK_A2BC870FF385AA49 FOREIGN KEY (instance_question_id) REFERENCES quiz_instance_question (id)');
        $this->addSql('ALTER TABLE quiz_instance_question ADD CONSTRAINT FK_3C49430A157761BD FOREIGN KEY (quiz_instance_id) REFERENCES quiz_instance (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_instance DROP FOREIGN KEY FK_94F4489B3EB8070A');
        $this->addSql('ALTER TABLE quiz_instance DROP FOREIGN KEY FK_94F4489B39A55F18');
        $this->addSql('ALTER TABLE quiz_instance DROP FOREIGN KEY FK_94F4489BB03A8386');
        $this->addSql('ALTER TABLE quiz_instance_answer DROP FOREIGN KEY FK_A2BC870FF385AA49');
        $this->addSql('ALTER TABLE quiz_instance_question DROP FOREIGN KEY FK_3C49430A157761BD');
        $this->addSql('DROP TABLE quiz_instance');
        $this->addSql('DROP TABLE quiz_instance_answer');
        $this->addSql('DROP TABLE quiz_instance_question');
    }
}
