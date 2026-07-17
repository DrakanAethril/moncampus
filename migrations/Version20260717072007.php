<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717072007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiz_template/quiz_question/quiz_answer (Générateur de quiz - teacher library).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quiz_answer (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(500) NOT NULL, is_correct TINYINT DEFAULT 0 NOT NULL, order_index INT NOT NULL, question_id INT NOT NULL, INDEX IDX_3799BA7C1E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_question (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, difficulty VARCHAR(20) DEFAULT NULL, label LONGTEXT NOT NULL, image_storage_key VARCHAR(255) DEFAULT NULL, order_index INT NOT NULL, quiz_template_id INT NOT NULL, INDEX IDX_6033B00B2AFC1C18 (quiz_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, subject VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, default_question_count INT NOT NULL, default_seconds_per_question INT NOT NULL, default_same_questions_for_all TINYINT DEFAULT 1 NOT NULL, default_question_order_per_student TINYINT DEFAULT 1 NOT NULL, default_answer_order_per_student TINYINT DEFAULT 0 NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, teacher_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_41A4E6C641807E1D (teacher_id), INDEX IDX_41A4E6C6B03A8386 (created_by_id), INDEX IDX_41A4E6C6F5A2E305 (inactivated_by_id), INDEX IDX_41A4E6C6E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_answer ADD CONSTRAINT FK_3799BA7C1E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_question (id)');
        $this->addSql('ALTER TABLE quiz_question ADD CONSTRAINT FK_6033B00B2AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id)');
        $this->addSql('ALTER TABLE quiz_template ADD CONSTRAINT FK_41A4E6C641807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_template ADD CONSTRAINT FK_41A4E6C6B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_template ADD CONSTRAINT FK_41A4E6C6F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_template ADD CONSTRAINT FK_41A4E6C6E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_answer DROP FOREIGN KEY FK_3799BA7C1E27F6BF');
        $this->addSql('ALTER TABLE quiz_question DROP FOREIGN KEY FK_6033B00B2AFC1C18');
        $this->addSql('ALTER TABLE quiz_template DROP FOREIGN KEY FK_41A4E6C641807E1D');
        $this->addSql('ALTER TABLE quiz_template DROP FOREIGN KEY FK_41A4E6C6B03A8386');
        $this->addSql('ALTER TABLE quiz_template DROP FOREIGN KEY FK_41A4E6C6F5A2E305');
        $this->addSql('ALTER TABLE quiz_template DROP FOREIGN KEY FK_41A4E6C6E562D849');
        $this->addSql('DROP TABLE quiz_answer');
        $this->addSql('DROP TABLE quiz_question');
        $this->addSql('DROP TABLE quiz_template');
    }
}
