<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Interactive video (créas 5B): the questions embedded in a video, and what students answered.
 *
 * `video_cue_point` is a placement rather than a copy - a timecode plus the id of a QuizQuestion
 * that already exists in the library - which is why it cascades from both sides: deleting the video
 * takes its markers with it, and deleting the question from the library does too, a marker with no
 * statement having nothing to ask.
 *
 * `video_cue_answer` carries one row per student and marker, hence the unique index: the answer
 * that counts is the first one, a second pass coming after the correction has been read.
 *
 * `video_resource.question_template_id` is the bank an import from a video writes into. Nullable
 * and ON DELETE SET NULL, because a teacher may well delete that quiz from their library - the
 * markers point at the questions, not at the bank.
 *
 * Nothing is rewritten: two tables and one column, no existing row touched.
 */
final class Version20260812101642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Questions incrustées dans une vidéo : video_cue_point, video_cue_answer et la banque de questions d\'une vidéo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_cue_answer (id INT AUTO_INCREMENT NOT NULL, correct TINYINT NOT NULL, answered_at DATETIME NOT NULL, cue_point_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_C540952E9443D67E (cue_point_id), INDEX IDX_C540952ECB944F1A (student_id), UNIQUE INDEX uniq_video_cue_answer_student (cue_point_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE video_cue_point (id INT AUTO_INCREMENT NOT NULL, timecode_seconds INT NOT NULL, pause_video TINYINT DEFAULT 1 NOT NULL, blocking TINYINT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, file_id INT NOT NULL, question_id INT NOT NULL, INDEX IDX_F0F996F793CB796C (file_id), INDEX IDX_F0F996F71E27F6BF (question_id), INDEX idx_video_cue_point_file_timecode (file_id, timecode_seconds), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE video_cue_answer ADD CONSTRAINT FK_C540952E9443D67E FOREIGN KEY (cue_point_id) REFERENCES video_cue_point (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_cue_answer ADD CONSTRAINT FK_C540952ECB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_cue_point ADD CONSTRAINT FK_F0F996F793CB796C FOREIGN KEY (file_id) REFERENCES video_resource_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_cue_point ADD CONSTRAINT FK_F0F996F71E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_resource ADD question_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE video_resource ADD CONSTRAINT FK_1413C6AAE6DED035 FOREIGN KEY (question_template_id) REFERENCES quiz_template (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1413C6AAE6DED035 ON video_resource (question_template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_cue_answer DROP FOREIGN KEY FK_C540952E9443D67E');
        $this->addSql('ALTER TABLE video_cue_answer DROP FOREIGN KEY FK_C540952ECB944F1A');
        $this->addSql('ALTER TABLE video_cue_point DROP FOREIGN KEY FK_F0F996F793CB796C');
        $this->addSql('ALTER TABLE video_cue_point DROP FOREIGN KEY FK_F0F996F71E27F6BF');
        $this->addSql('DROP TABLE video_cue_answer');
        $this->addSql('DROP TABLE video_cue_point');
        $this->addSql('ALTER TABLE video_resource DROP FOREIGN KEY FK_1413C6AAE6DED035');
        $this->addSql('DROP INDEX IDX_1413C6AAE6DED035 ON video_resource');
        $this->addSql('ALTER TABLE video_resource DROP question_template_id');
    }
}
