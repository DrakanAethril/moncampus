<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717082931 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiz_attempt/quiz_attempt_answer/quiz_attempt_selected_answer (Générateur de quiz - student passation).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quiz_attempt (id INT AUTO_INCREMENT NOT NULL, attempt_number INT NOT NULL, status VARCHAR(20) DEFAULT NULL, origin VARCHAR(20) NOT NULL, shuffle_seed INT NOT NULL, started_at DATETIME NOT NULL, submitted_at DATETIME DEFAULT NULL, correct_count INT DEFAULT NULL, question_total INT DEFAULT NULL, quiz_instance_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_AB6AFC6157761BD (quiz_instance_id), INDEX IDX_AB6AFC6CB944F1A (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_attempt_answer (id INT AUTO_INCREMENT NOT NULL, order_index INT NOT NULL, answered_at DATETIME DEFAULT NULL, is_correct TINYINT DEFAULT NULL, attempt_id INT NOT NULL, instance_question_id INT NOT NULL, INDEX IDX_9453B9FCB191BE6B (attempt_id), INDEX IDX_9453B9FCF385AA49 (instance_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_attempt_selected_answer (id INT AUTO_INCREMENT NOT NULL, order_index INT NOT NULL, attempt_answer_id INT NOT NULL, instance_answer_id INT NOT NULL, INDEX IDX_24CF91A95EE572C (attempt_answer_id), INDEX IDX_24CF91AAF31B513 (instance_answer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6157761BD FOREIGN KEY (quiz_instance_id) REFERENCES quiz_instance (id)');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD CONSTRAINT FK_9453B9FCB191BE6B FOREIGN KEY (attempt_id) REFERENCES quiz_attempt (id)');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD CONSTRAINT FK_9453B9FCF385AA49 FOREIGN KEY (instance_question_id) REFERENCES quiz_instance_question (id)');
        $this->addSql('ALTER TABLE quiz_attempt_selected_answer ADD CONSTRAINT FK_24CF91A95EE572C FOREIGN KEY (attempt_answer_id) REFERENCES quiz_attempt_answer (id)');
        $this->addSql('ALTER TABLE quiz_attempt_selected_answer ADD CONSTRAINT FK_24CF91AAF31B513 FOREIGN KEY (instance_answer_id) REFERENCES quiz_instance_answer (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_attempt DROP FOREIGN KEY FK_AB6AFC6157761BD');
        $this->addSql('ALTER TABLE quiz_attempt DROP FOREIGN KEY FK_AB6AFC6CB944F1A');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP FOREIGN KEY FK_9453B9FCB191BE6B');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP FOREIGN KEY FK_9453B9FCF385AA49');
        $this->addSql('ALTER TABLE quiz_attempt_selected_answer DROP FOREIGN KEY FK_24CF91A95EE572C');
        $this->addSql('ALTER TABLE quiz_attempt_selected_answer DROP FOREIGN KEY FK_24CF91AAF31B513');
        $this->addSql('DROP TABLE quiz_attempt');
        $this->addSql('DROP TABLE quiz_attempt_answer');
        $this->addSql('DROP TABLE quiz_attempt_selected_answer');
    }
}
