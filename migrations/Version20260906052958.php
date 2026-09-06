<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Nuage de mots » (design_handoff_nuage_de_mots): the tool's two tables and the join table of its
 * targeted options.
 *
 * There is no `status` column, deliberately: `Programmé` / `Ouvert` / `Clos` is read off the window
 * by App\Service\WordCloud\WordCloudSchedule. Nor is there a count of anything - a cloud's words,
 * participants and ranking are the sum of its `word_cloud_submission` rows.
 *
 * The `word_cloud` feature itself needs no data: the catalogue lives in App\Enum\Feature and the
 * database stores only the deviations from it.
 */
final class Version20260906052958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nuage de mots: word_cloud, word_cloud_option, word_cloud_submission';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE word_cloud (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, question VARCHAR(500) NOT NULL, opens_at DATETIME DEFAULT NULL, closes_at DATETIME DEFAULT NULL, manual_opening TINYINT NOT NULL, opened_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, words_per_student INT DEFAULT NULL, word_length VARCHAR(20) NOT NULL, projection_mode VARCHAR(20) NOT NULL, group_variants TINYINT NOT NULL, moderation TINYINT NOT NULL, visible_to_students TINYINT NOT NULL, created_at DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, teacher_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_ECEF1D123EB8070A (program_id), INDEX IDX_ECEF1D1241807E1D (teacher_id), INDEX IDX_ECEF1D12B03A8386 (created_by_id), INDEX IDX_ECEF1D12F5A2E305 (inactivated_by_id), INDEX IDX_ECEF1D12E562D849 (last_updated_by_id), INDEX idx_word_cloud_program_created (program_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE word_cloud_option (word_cloud_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_DA91D51FB63A2137 (word_cloud_id), INDEX IDX_DA91D51FA7C41D6F (option_id), PRIMARY KEY (word_cloud_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE word_cloud_submission (id INT AUTO_INCREMENT NOT NULL, text VARCHAR(60) NOT NULL, normalized_text VARCHAR(60) NOT NULL, submitted_at DATETIME NOT NULL, moderation_state VARCHAR(20) NOT NULL, word_cloud_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_AD74A3CDB63A2137 (word_cloud_id), INDEX IDX_AD74A3CDCB944F1A (student_id), INDEX idx_word_cloud_submission_cloud_time (word_cloud_id, submitted_at), UNIQUE INDEX uniq_word_cloud_student_word (word_cloud_id, student_id, normalized_text), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE word_cloud ADD CONSTRAINT FK_ECEF1D123EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE word_cloud ADD CONSTRAINT FK_ECEF1D1241807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE word_cloud ADD CONSTRAINT FK_ECEF1D12B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE word_cloud ADD CONSTRAINT FK_ECEF1D12F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE word_cloud ADD CONSTRAINT FK_ECEF1D12E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE word_cloud_option ADD CONSTRAINT FK_DA91D51FB63A2137 FOREIGN KEY (word_cloud_id) REFERENCES word_cloud (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE word_cloud_option ADD CONSTRAINT FK_DA91D51FA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE word_cloud_submission ADD CONSTRAINT FK_AD74A3CDB63A2137 FOREIGN KEY (word_cloud_id) REFERENCES word_cloud (id)');
        $this->addSql('ALTER TABLE word_cloud_submission ADD CONSTRAINT FK_AD74A3CDCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');

        // The role matrix gains its eight rows. Written out rather than derived from the enum, like
        // every other seeding migration here: a migration must keep meaning what it meant, even if
        // the catalogue's defaults move afterwards.
        //
        // On for the two roles that are in the room. Splitting them would have been worse than
        // useless: a cloud lit for teachers alone is a question nobody can answer, and one lit for
        // students alone is a question nobody can ask.
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_STUDENT', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_TEACHER', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_STAFF', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_STAFF-LEAD', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_TUTOR', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_SUPPORT-TECH', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_ECO', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('word_cloud', 'ROLE_EXTERNAL', 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE word_cloud DROP FOREIGN KEY FK_ECEF1D123EB8070A');
        $this->addSql('ALTER TABLE word_cloud DROP FOREIGN KEY FK_ECEF1D1241807E1D');
        $this->addSql('ALTER TABLE word_cloud DROP FOREIGN KEY FK_ECEF1D12B03A8386');
        $this->addSql('ALTER TABLE word_cloud DROP FOREIGN KEY FK_ECEF1D12F5A2E305');
        $this->addSql('ALTER TABLE word_cloud DROP FOREIGN KEY FK_ECEF1D12E562D849');
        $this->addSql('ALTER TABLE word_cloud_option DROP FOREIGN KEY FK_DA91D51FB63A2137');
        $this->addSql('ALTER TABLE word_cloud_option DROP FOREIGN KEY FK_DA91D51FA7C41D6F');
        $this->addSql('ALTER TABLE word_cloud_submission DROP FOREIGN KEY FK_AD74A3CDB63A2137');
        $this->addSql('ALTER TABLE word_cloud_submission DROP FOREIGN KEY FK_AD74A3CDCB944F1A');
        $this->addSql('DROP TABLE word_cloud');
        $this->addSql('DROP TABLE word_cloud_option');
        $this->addSql('DROP TABLE word_cloud_submission');
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'word_cloud'");
    }
}
