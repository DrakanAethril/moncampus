<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Cours en ligne », lot 4 (design/validated/cours-en-ligne.md §10): learning paths, their steps,
 * and what following one leaves behind - the enrollment, the steps opened, the quiz attempts.
 */
final class Version20261002210803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Learning paths: paths, steps, enrollments, step visits and quiz attempts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE learning_path (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, summary VARCHAR(300) NOT NULL, description LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_4D04C7977E3C61F9 (owner_id), INDEX idx_learning_path_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE learning_path_enrollment (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, path_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_3DAC50A6D96C566B (path_id), INDEX IDX_3DAC50A6A76ED395 (user_id), INDEX idx_learning_path_enrollment_activity (last_activity_at), UNIQUE INDEX uniq_learning_path_enrollment (path_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE learning_path_quiz_attempt (id INT AUTO_INCREMENT NOT NULL, questions JSON NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, score_percent INT DEFAULT NULL, enrollment_id INT NOT NULL, step_id INT NOT NULL, INDEX IDX_C65286F78F7DB25B (enrollment_id), INDEX IDX_C65286F773B21E9C (step_id), INDEX idx_learning_path_quiz_attempt_step (enrollment_id, step_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE learning_path_step (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, type VARCHAR(10) NOT NULL, pass_percent INT DEFAULT NULL, question_count INT DEFAULT NULL, path_id INT NOT NULL, course_id INT DEFAULT NULL, quiz_template_id INT DEFAULT NULL, INDEX IDX_D4FE3A0CD96C566B (path_id), INDEX IDX_D4FE3A0C591CC992 (course_id), INDEX IDX_D4FE3A0C2AFC1C18 (quiz_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE learning_path_step_visit (id INT AUTO_INCREMENT NOT NULL, opened_at DATETIME NOT NULL, enrollment_id INT NOT NULL, step_id INT NOT NULL, INDEX IDX_B92C99818F7DB25B (enrollment_id), INDEX IDX_B92C998173B21E9C (step_id), UNIQUE INDEX uniq_learning_path_step_visit (enrollment_id, step_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE learning_path ADD CONSTRAINT FK_4D04C7977E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_enrollment ADD CONSTRAINT FK_3DAC50A6D96C566B FOREIGN KEY (path_id) REFERENCES learning_path (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_enrollment ADD CONSTRAINT FK_3DAC50A6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_quiz_attempt ADD CONSTRAINT FK_C65286F78F7DB25B FOREIGN KEY (enrollment_id) REFERENCES learning_path_enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_quiz_attempt ADD CONSTRAINT FK_C65286F773B21E9C FOREIGN KEY (step_id) REFERENCES learning_path_step (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_step ADD CONSTRAINT FK_D4FE3A0CD96C566B FOREIGN KEY (path_id) REFERENCES learning_path (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_step ADD CONSTRAINT FK_D4FE3A0C591CC992 FOREIGN KEY (course_id) REFERENCES online_course (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_step ADD CONSTRAINT FK_D4FE3A0C2AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE learning_path_step_visit ADD CONSTRAINT FK_B92C99818F7DB25B FOREIGN KEY (enrollment_id) REFERENCES learning_path_enrollment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE learning_path_step_visit ADD CONSTRAINT FK_B92C998173B21E9C FOREIGN KEY (step_id) REFERENCES learning_path_step (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learning_path DROP FOREIGN KEY FK_4D04C7977E3C61F9');
        $this->addSql('ALTER TABLE learning_path_enrollment DROP FOREIGN KEY FK_3DAC50A6D96C566B');
        $this->addSql('ALTER TABLE learning_path_enrollment DROP FOREIGN KEY FK_3DAC50A6A76ED395');
        $this->addSql('ALTER TABLE learning_path_quiz_attempt DROP FOREIGN KEY FK_C65286F78F7DB25B');
        $this->addSql('ALTER TABLE learning_path_quiz_attempt DROP FOREIGN KEY FK_C65286F773B21E9C');
        $this->addSql('ALTER TABLE learning_path_step DROP FOREIGN KEY FK_D4FE3A0CD96C566B');
        $this->addSql('ALTER TABLE learning_path_step DROP FOREIGN KEY FK_D4FE3A0C591CC992');
        $this->addSql('ALTER TABLE learning_path_step DROP FOREIGN KEY FK_D4FE3A0C2AFC1C18');
        $this->addSql('ALTER TABLE learning_path_step_visit DROP FOREIGN KEY FK_B92C99818F7DB25B');
        $this->addSql('ALTER TABLE learning_path_step_visit DROP FOREIGN KEY FK_B92C998173B21E9C');
        $this->addSql('DROP TABLE learning_path');
        $this->addSql('DROP TABLE learning_path_enrollment');
        $this->addSql('DROP TABLE learning_path_quiz_attempt');
        $this->addSql('DROP TABLE learning_path_step');
        $this->addSql('DROP TABLE learning_path_step_visit');
    }
}
