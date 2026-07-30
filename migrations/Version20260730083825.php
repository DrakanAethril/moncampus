<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Progression pédagogique module (design/design_handoff_progression) - the four progression_*
 * tables, plus the evaluation columns it needs: nature (D/F/S), and the optional links to the
 * séquence it was posed from and the créneau it sits on. Every added evaluation column is
 * nullable: existing rows keep no nature, which is a valid state (see App\Enum\EvaluationNature).
 */
final class Version20260730083825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression pédagogique: progression, progression_sequence, progression_seance, progression_seance_placement + evaluation.nature/progression_sequence_id/lesson_session_id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE progression (id INT AUTO_INCREMENT NOT NULL, display_step VARCHAR(20) NOT NULL, creation_date DATETIME NOT NULL, topic_id INT NOT NULL, teacher_id INT NOT NULL, UNIQUE INDEX UNIQ_D5B250731F55203D (topic_id), INDEX IDX_D5B2507341807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE progression_seance (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, position INT NOT NULL, planned_duration NUMERIC(10, 2) DEFAULT NULL, per_group TINYINT NOT NULL, removed TINYINT NOT NULL, too_short TINYINT NOT NULL, progression_sequence_id INT NOT NULL, seance_instance_id INT DEFAULT NULL, INDEX IDX_8CCDCA1339BF7A02 (progression_sequence_id), INDEX IDX_8CCDCA13783B956 (seance_instance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE progression_seance_placement (id INT AUTO_INCREMENT NOT NULL, part_index INT NOT NULL, duration NUMERIC(10, 2) DEFAULT NULL, confirmed TINYINT NOT NULL, snapshot_day DATE DEFAULT NULL, snapshot_start_hour TIME DEFAULT NULL, snapshot_end_hour TIME DEFAULT NULL, progression_seance_id INT NOT NULL, lesson_session_id INT DEFAULT NULL, option_id INT DEFAULT NULL, INDEX IDX_4A22B54EACE1595C (progression_seance_id), INDEX IDX_4A22B54E6C36A50E (lesson_session_id), INDEX IDX_4A22B54EA7C41D6F (option_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE progression_sequence (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, forced_start_date DATE DEFAULT NULL, place_in_timetable TINYINT NOT NULL, truncated_by_next TINYINT NOT NULL, progression_id INT NOT NULL, sequence_instance_id INT NOT NULL, INDEX IDX_15574D88AF229C18 (progression_id), INDEX IDX_15574D889C94529B (sequence_instance_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE progression ADD CONSTRAINT FK_D5B250731F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression ADD CONSTRAINT FK_D5B2507341807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE progression_seance ADD CONSTRAINT FK_8CCDCA1339BF7A02 FOREIGN KEY (progression_sequence_id) REFERENCES progression_sequence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression_seance ADD CONSTRAINT FK_8CCDCA13783B956 FOREIGN KEY (seance_instance_id) REFERENCES seance_instance (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE progression_seance_placement ADD CONSTRAINT FK_4A22B54EACE1595C FOREIGN KEY (progression_seance_id) REFERENCES progression_seance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression_seance_placement ADD CONSTRAINT FK_4A22B54E6C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE progression_seance_placement ADD CONSTRAINT FK_4A22B54EA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE progression_sequence ADD CONSTRAINT FK_15574D88AF229C18 FOREIGN KEY (progression_id) REFERENCES progression (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE progression_sequence ADD CONSTRAINT FK_15574D889C94529B FOREIGN KEY (sequence_instance_id) REFERENCES sequence_instance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation ADD nature VARCHAR(20) DEFAULT NULL, ADD progression_sequence_id INT DEFAULT NULL, ADD lesson_session_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A57539BF7A02 FOREIGN KEY (progression_sequence_id) REFERENCES progression_sequence (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A5756C36A50E FOREIGN KEY (lesson_session_id) REFERENCES lesson_session (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1323A57539BF7A02 ON evaluation (progression_sequence_id)');
        $this->addSql('CREATE INDEX IDX_1323A5756C36A50E ON evaluation (lesson_session_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE progression DROP FOREIGN KEY FK_D5B250731F55203D');
        $this->addSql('ALTER TABLE progression DROP FOREIGN KEY FK_D5B2507341807E1D');
        $this->addSql('ALTER TABLE progression_seance DROP FOREIGN KEY FK_8CCDCA1339BF7A02');
        $this->addSql('ALTER TABLE progression_seance DROP FOREIGN KEY FK_8CCDCA13783B956');
        $this->addSql('ALTER TABLE progression_seance_placement DROP FOREIGN KEY FK_4A22B54EACE1595C');
        $this->addSql('ALTER TABLE progression_seance_placement DROP FOREIGN KEY FK_4A22B54E6C36A50E');
        $this->addSql('ALTER TABLE progression_seance_placement DROP FOREIGN KEY FK_4A22B54EA7C41D6F');
        $this->addSql('ALTER TABLE progression_sequence DROP FOREIGN KEY FK_15574D88AF229C18');
        $this->addSql('ALTER TABLE progression_sequence DROP FOREIGN KEY FK_15574D889C94529B');
        $this->addSql('DROP TABLE progression');
        $this->addSql('DROP TABLE progression_seance');
        $this->addSql('DROP TABLE progression_seance_placement');
        $this->addSql('DROP TABLE progression_sequence');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A57539BF7A02');
        $this->addSql('ALTER TABLE evaluation DROP FOREIGN KEY FK_1323A5756C36A50E');
        $this->addSql('DROP INDEX IDX_1323A57539BF7A02 ON evaluation');
        $this->addSql('DROP INDEX IDX_1323A5756C36A50E ON evaluation');
        $this->addSql('ALTER TABLE evaluation DROP nature, DROP progression_sequence_id, DROP lesson_session_id');
    }
}
