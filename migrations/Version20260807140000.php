<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The teacher tool "Enregistrements audio" (design_handoff_enregistrements_audio): a batch of audio
 * files per class, common or individualised, and the listen tracking that decides the completion of
 * a Listening assignment.
 *
 * The grade_audio_comment table goes in the same move: the gradebook's audio comments are replaced
 * by this tool, which inherits their recorder and their format. The objects already written to the
 * bucket under audio-appreciations/ are left alone - a schema migration does not erase files, and
 * they cost nothing to leave sleeping.
 */
final class Version20260807140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audio recordings tool: recordings, files and listen tracking (drops the gradebook audio comments)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audio_recording (
            id INT AUTO_INCREMENT NOT NULL,
            program_id INT NOT NULL,
            assignment_id INT DEFAULT NULL,
            created_by_id INT NOT NULL,
            inactivated_by_id INT DEFAULT NULL,
            last_updated_by_id INT DEFAULT NULL,
            last_updated_date DATETIME DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            mode VARCHAR(20) NOT NULL,
            INDEX IDX_EF0226533EB8070A (program_id),
            INDEX IDX_EF022653D19302F8 (assignment_id),
            INDEX IDX_EF022653B03A8386 (created_by_id),
            INDEX IDX_EF022653F5A2E305 (inactivated_by_id),
            INDEX IDX_EF022653E562D849 (last_updated_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE audio_recording_option (
            audio_recording_id INT NOT NULL,
            option_id INT NOT NULL,
            INDEX IDX_94A1F03C256BB73E (audio_recording_id),
            INDEX IDX_94A1F03CA7C41D6F (option_id),
            PRIMARY KEY(audio_recording_id, option_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE audio_recording_file (
            id INT AUTO_INCREMENT NOT NULL,
            recording_id INT NOT NULL,
            student_id INT DEFAULT NULL,
            recorded_by_id INT NOT NULL,
            storage_key VARCHAR(255) NOT NULL,
            duration_seconds INT NOT NULL,
            file_size INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            position INT NOT NULL,
            recorded_at DATETIME NOT NULL,
            INDEX IDX_F13238BF8CA9A845 (recording_id),
            INDEX IDX_F13238BFCB944F1A (student_id),
            INDEX IDX_F13238BFD05A957B (recorded_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE audio_listen_progress (
            id INT AUTO_INCREMENT NOT NULL,
            file_id INT NOT NULL,
            student_id INT NOT NULL,
            max_listened_percent INT NOT NULL,
            last_listened_at DATETIME DEFAULT NULL,
            INDEX IDX_3610B26393CB796C (file_id),
            INDEX IDX_3610B263CB944F1A (student_id),
            UNIQUE INDEX uniq_audio_listen_file_student (file_id, student_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE audio_recording ADD CONSTRAINT FK_audio_recording_program FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE audio_recording ADD CONSTRAINT FK_audio_recording_assignment FOREIGN KEY (assignment_id) REFERENCES assignment (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE audio_recording ADD CONSTRAINT FK_audio_recording_created_by FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE audio_recording ADD CONSTRAINT FK_audio_recording_inactivated_by FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE audio_recording ADD CONSTRAINT FK_audio_recording_last_updated_by FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE audio_recording_option ADD CONSTRAINT FK_audio_recording_option_recording FOREIGN KEY (audio_recording_id) REFERENCES audio_recording (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audio_recording_option ADD CONSTRAINT FK_audio_recording_option_option FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE audio_recording_file ADD CONSTRAINT FK_audio_recording_file_recording FOREIGN KEY (recording_id) REFERENCES audio_recording (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audio_recording_file ADD CONSTRAINT FK_audio_recording_file_student FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audio_recording_file ADD CONSTRAINT FK_audio_recording_file_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES `user` (id)');

        $this->addSql('ALTER TABLE audio_listen_progress ADD CONSTRAINT FK_audio_listen_progress_file FOREIGN KEY (file_id) REFERENCES audio_recording_file (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audio_listen_progress ADD CONSTRAINT FK_audio_listen_progress_student FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');

        // A Listening assignment names the recording it asks to listen to, as a Quiz assignment names
        // its instance.
        $this->addSql('ALTER TABLE assignment ADD audio_recording_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_assignment_audio_recording FOREIGN KEY (audio_recording_id) REFERENCES audio_recording (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BA256BB73E ON assignment (audio_recording_id)');

        $this->addSql('DROP TABLE grade_audio_comment');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_assignment_audio_recording');
        $this->addSql('DROP INDEX IDX_30C544BA256BB73E ON assignment');
        $this->addSql('ALTER TABLE assignment DROP audio_recording_id');

        $this->addSql('DROP TABLE audio_listen_progress');
        $this->addSql('DROP TABLE audio_recording_file');
        $this->addSql('DROP TABLE audio_recording_option');
        $this->addSql('DROP TABLE audio_recording');

        // The schema comes back, the comments do not: up() dropped them. The bucket objects, for
        // their part, were never touched.
        $this->addSql('CREATE TABLE grade_audio_comment (
            id INT AUTO_INCREMENT NOT NULL,
            grade_id INT NOT NULL,
            recorded_by_id INT NOT NULL,
            s3_key VARCHAR(255) NOT NULL,
            file_size INT NOT NULL,
            recorded_at DATETIME NOT NULL,
            max_listened_percent INT NOT NULL,
            last_listened_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_grade_audio_comment_grade (grade_id),
            INDEX IDX_grade_audio_comment_recorded_by (recorded_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE grade_audio_comment ADD CONSTRAINT FK_grade_audio_comment_grade FOREIGN KEY (grade_id) REFERENCES grade (id)');
        $this->addSql('ALTER TABLE grade_audio_comment ADD CONSTRAINT FK_grade_audio_comment_recorded_by FOREIGN KEY (recorded_by_id) REFERENCES `user` (id)');
    }
}
