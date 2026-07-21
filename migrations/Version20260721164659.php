<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721164659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evaluation (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, modality VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, date DATETIME NOT NULL, scale DOUBLE PRECISION NOT NULL, coefficient DOUBLE PRECISION NOT NULL, counts_out_of_20 TINYINT NOT NULL, visible_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, topic_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_1323A5751F55203D (topic_id), INDEX IDX_1323A575B03A8386 (created_by_id), INDEX IDX_1323A575F5A2E305 (inactivated_by_id), INDEX IDX_1323A575E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evaluation_rubric_question (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(20) NOT NULL, max_points DOUBLE PRECISION NOT NULL, position INT NOT NULL, section_id INT NOT NULL, INDEX IDX_4B85A498D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evaluation_rubric_section (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, position INT NOT NULL, evaluation_id INT NOT NULL, INDEX IDX_3D67D6F5456C5646 (evaluation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, value DOUBLE PRECISION DEFAULT NULL, graded_at DATETIME DEFAULT NULL, evaluation_id INT NOT NULL, student_id INT NOT NULL, graded_by_id INT DEFAULT NULL, INDEX IDX_595AAE34456C5646 (evaluation_id), INDEX IDX_595AAE34CB944F1A (student_id), INDEX IDX_595AAE34C814BC2E (graded_by_id), UNIQUE INDEX uniq_evaluation_student (evaluation_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade_audio_comment (id INT AUTO_INCREMENT NOT NULL, s3_key VARCHAR(255) NOT NULL, file_size INT NOT NULL, recorded_at DATETIME NOT NULL, max_listened_percent INT NOT NULL, last_listened_at DATETIME DEFAULT NULL, grade_id INT NOT NULL, recorded_by_id INT NOT NULL, UNIQUE INDEX UNIQ_8AA4E3AFFE19A1A8 (grade_id), INDEX IDX_8AA4E3AFD05A957B (recorded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE grade_rubric_answer (id INT AUTO_INCREMENT NOT NULL, points_awarded DOUBLE PRECISION DEFAULT NULL, not_tested TINYINT NOT NULL, grade_id INT NOT NULL, question_id INT NOT NULL, INDEX IDX_A2705995FE19A1A8 (grade_id), INDEX IDX_A27059951E27F6BF (question_id), UNIQUE INDEX uniq_grade_question (grade_id, question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A5751F55203D FOREIGN KEY (topic_id) REFERENCES topic (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE evaluation_rubric_question ADD CONSTRAINT FK_4B85A498D823E37A FOREIGN KEY (section_id) REFERENCES evaluation_rubric_section (id)');
        $this->addSql('ALTER TABLE evaluation_rubric_section ADD CONSTRAINT FK_3D67D6F5456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id)');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE34456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id)');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE34CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE grade ADD CONSTRAINT FK_595AAE34C814BC2E FOREIGN KEY (graded_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE grade_audio_comment ADD CONSTRAINT FK_8AA4E3AFFE19A1A8 FOREIGN KEY (grade_id) REFERENCES grade (id)');
        $this->addSql('ALTER TABLE grade_audio_comment ADD CONSTRAINT FK_8AA4E3AFD05A957B FOREIGN KEY (recorded_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE grade_rubric_answer ADD CONSTRAINT FK_A2705995FE19A1A8 FOREIGN KEY (grade_id) REFERENCES grade (id)');
        $this->addSql('ALTER TABLE grade_rubric_answer ADD CONSTRAINT FK_A27059951E27F6BF FOREIGN KEY (question_id) REFERENCES evaluation_rubric_question (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A5751F55203D');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575B03A8386');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575F5A2E305');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A575E562D849');
        $this->addSql('ALTER TABLE evaluation_rubric_question DROP FOREIGN KEY FK_4B85A498D823E37A');
        $this->addSql('ALTER TABLE evaluation_rubric_section DROP FOREIGN KEY FK_3D67D6F5456C5646');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE34456C5646');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE34CB944F1A');
        $this->addSql('ALTER TABLE grade DROP FOREIGN KEY FK_595AAE34C814BC2E');
        $this->addSql('ALTER TABLE grade_audio_comment DROP FOREIGN KEY FK_8AA4E3AFFE19A1A8');
        $this->addSql('ALTER TABLE grade_audio_comment DROP FOREIGN KEY FK_8AA4E3AFD05A957B');
        $this->addSql('ALTER TABLE grade_rubric_answer DROP FOREIGN KEY FK_A2705995FE19A1A8');
        $this->addSql('ALTER TABLE grade_rubric_answer DROP FOREIGN KEY FK_A27059951E27F6BF');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('DROP TABLE evaluation_rubric_question');
        $this->addSql('DROP TABLE evaluation_rubric_section');
        $this->addSql('DROP TABLE grade');
        $this->addSql('DROP TABLE grade_audio_comment');
        $this->addSql('DROP TABLE grade_rubric_answer');
    }
}
