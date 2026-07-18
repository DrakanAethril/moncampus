<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260717211856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quiz_live_session/quiz_live_participant/quiz_live_answer for the Kahoot-style live multiplayer quiz mode';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quiz_live_answer (id INT AUTO_INCREMENT NOT NULL, is_correct TINYINT DEFAULT NULL, answered_at DATETIME DEFAULT NULL, points_awarded INT NOT NULL, participant_id INT NOT NULL, instance_question_id INT NOT NULL, selected_answer_id INT DEFAULT NULL, INDEX IDX_96ADEF519D1C3019 (participant_id), INDEX IDX_96ADEF51F385AA49 (instance_question_id), INDEX IDX_96ADEF51F24C5BEC (selected_answer_id), UNIQUE INDEX uniq_quiz_live_answer_participant_question (participant_id, instance_question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_live_participant (id INT AUTO_INCREMENT NOT NULL, display_name VARCHAR(100) NOT NULL, score INT NOT NULL, joined_at DATETIME NOT NULL, session_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_A945AC70613FECDF (session_id), INDEX IDX_A945AC70CB944F1A (student_id), UNIQUE INDEX uniq_quiz_live_participant_session_student (session_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_live_session (id INT AUTO_INCREMENT NOT NULL, room_code VARCHAR(8) NOT NULL, status VARCHAR(20) NOT NULL, current_question_index INT DEFAULT NULL, phase_started_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, quiz_instance_id INT NOT NULL, host_id INT NOT NULL, UNIQUE INDEX UNIQ_876010541411DAFC (room_code), UNIQUE INDEX UNIQ_87601054157761BD (quiz_instance_id), INDEX IDX_876010541FB8D185 (host_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_live_answer ADD CONSTRAINT FK_96ADEF519D1C3019 FOREIGN KEY (participant_id) REFERENCES quiz_live_participant (id)');
        $this->addSql('ALTER TABLE quiz_live_answer ADD CONSTRAINT FK_96ADEF51F385AA49 FOREIGN KEY (instance_question_id) REFERENCES quiz_instance_question (id)');
        $this->addSql('ALTER TABLE quiz_live_answer ADD CONSTRAINT FK_96ADEF51F24C5BEC FOREIGN KEY (selected_answer_id) REFERENCES quiz_instance_answer (id)');
        $this->addSql('ALTER TABLE quiz_live_participant ADD CONSTRAINT FK_A945AC70613FECDF FOREIGN KEY (session_id) REFERENCES quiz_live_session (id)');
        $this->addSql('ALTER TABLE quiz_live_participant ADD CONSTRAINT FK_A945AC70CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_live_session ADD CONSTRAINT FK_87601054157761BD FOREIGN KEY (quiz_instance_id) REFERENCES quiz_instance (id)');
        $this->addSql('ALTER TABLE quiz_live_session ADD CONSTRAINT FK_876010541FB8D185 FOREIGN KEY (host_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE quiz_live_answer DROP FOREIGN KEY FK_96ADEF519D1C3019');
        $this->addSql('ALTER TABLE quiz_live_answer DROP FOREIGN KEY FK_96ADEF51F385AA49');
        $this->addSql('ALTER TABLE quiz_live_answer DROP FOREIGN KEY FK_96ADEF51F24C5BEC');
        $this->addSql('ALTER TABLE quiz_live_participant DROP FOREIGN KEY FK_A945AC70613FECDF');
        $this->addSql('ALTER TABLE quiz_live_participant DROP FOREIGN KEY FK_A945AC70CB944F1A');
        $this->addSql('ALTER TABLE quiz_live_session DROP FOREIGN KEY FK_87601054157761BD');
        $this->addSql('ALTER TABLE quiz_live_session DROP FOREIGN KEY FK_876010541FB8D185');
        $this->addSql('DROP TABLE quiz_live_answer');
        $this->addSql('DROP TABLE quiz_live_participant');
        $this->addSql('DROP TABLE quiz_live_session');
    }
}
