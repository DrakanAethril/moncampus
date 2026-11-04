<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The quiz tournaments (design/validated/quiz-tournament.md §9): the tournament with its targeting
 * (Sections, Tracks, Programs - the shared StructureTargetsTrait) and its source quizzes, the
 * players, the challenges and their rooms, the rounds in class, the duels with their frozen
 * questions and the answers.
 */
final class Version20261104133548 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz tournaments: tournament, targeting, players, challenges, rounds, duels, questions, answers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_tournament (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, palette VARCHAR(20) NOT NULL, logo_key VARCHAR(255) DEFAULT NULL, questions_per_duel SMALLINT NOT NULL, max_true_false_per_duel SMALLINT NOT NULL, seconds_per_question SMALLINT NOT NULL, seconds_per_multi_question SMALLINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, opened_at DATETIME DEFAULT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, opened_by_id INT DEFAULT NULL, INDEX IDX_F4309FE5B03A8386 (created_by_id), INDEX IDX_F4309FE5896DBBDE (updated_by_id), INDEX IDX_F4309FE5AB159F5 (opened_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_source (quiz_tournament_id INT NOT NULL, quiz_template_id INT NOT NULL, INDEX IDX_B3348CA0196871F6 (quiz_tournament_id), INDEX IDX_B3348CA02AFC1C18 (quiz_template_id), PRIMARY KEY (quiz_tournament_id, quiz_template_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_section (quiz_tournament_id INT NOT NULL, section_id INT NOT NULL, INDEX IDX_32454772196871F6 (quiz_tournament_id), INDEX IDX_32454772D823E37A (section_id), PRIMARY KEY (quiz_tournament_id, section_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_track (quiz_tournament_id INT NOT NULL, track_id INT NOT NULL, INDEX IDX_BE20D67196871F6 (quiz_tournament_id), INDEX IDX_BE20D675ED23C43 (track_id), PRIMARY KEY (quiz_tournament_id, track_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_program (quiz_tournament_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_8DDB4A19196871F6 (quiz_tournament_id), INDEX IDX_8DDB4A193EB8070A (program_id), PRIMARY KEY (quiz_tournament_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_challenge (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, ladder TINYINT NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, responded_at DATETIME DEFAULT NULL, room_closes_at DATETIME DEFAULT NULL, challenger_ready_at DATETIME DEFAULT NULL, challenged_ready_at DATETIME DEFAULT NULL, tournament_id INT NOT NULL, challenger_id INT NOT NULL, challenged_id INT NOT NULL, duel_id INT DEFAULT NULL, INDEX IDX_4B8E2E2133D1A3E7 (tournament_id), INDEX IDX_4B8E2E212D521FDF (challenger_id), INDEX IDX_4B8E2E215820179C (challenged_id), UNIQUE INDEX UNIQ_4B8E2E2158875E (duel_id), INDEX quiz_tournament_challenge_outgoing (tournament_id, challenger_id, status), INDEX quiz_tournament_challenge_incoming (tournament_id, challenged_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_duel (id INT AUTO_INCREMENT NOT NULL, origin VARCHAR(20) NOT NULL, seed INT UNSIGNED NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, score_a NUMERIC(6, 2) NOT NULL, score_b NUMERIC(6, 2) NOT NULL, rating_before_a INT NOT NULL, rating_before_b INT NOT NULL, rating_after_a INT DEFAULT NULL, rating_after_b INT DEFAULT NULL, ladder_a TINYINT NOT NULL, ladder_b TINYINT NOT NULL, tournament_id INT NOT NULL, player_a_id INT NOT NULL, player_b_id INT NOT NULL, round_id INT DEFAULT NULL, INDEX IDX_973DAF0D33D1A3E7 (tournament_id), INDEX IDX_973DAF0D99C4036B (player_a_id), INDEX IDX_973DAF0D8B71AC85 (player_b_id), INDEX IDX_973DAF0DA6005CA0 (round_id), INDEX quiz_tournament_duel_started (tournament_id, started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_duel_answer (id INT AUTO_INCREMENT NOT NULL, selected_choice_ids JSON NOT NULL, numeric_value DOUBLE PRECISION DEFAULT NULL, numeric_unit VARCHAR(50) DEFAULT NULL, points NUMERIC(5, 2) NOT NULL, answered_at DATETIME NOT NULL, question_id INT NOT NULL, player_id INT NOT NULL, INDEX IDX_D355CA771E27F6BF (question_id), INDEX IDX_D355CA7799E6F5DF (player_id), UNIQUE INDEX quiz_tournament_duel_answer_unique (question_id, player_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_duel_question (id INT AUTO_INCREMENT NOT NULL, `rank` SMALLINT NOT NULL, type VARCHAR(20) NOT NULL, label LONGTEXT NOT NULL, image_storage_key VARCHAR(255) DEFAULT NULL, choices JSON NOT NULL, source_quiz_name VARCHAR(255) NOT NULL, time_limit_seconds SMALLINT NOT NULL, served_at DATETIME DEFAULT NULL, deadline_at DATETIME DEFAULT NULL, blanks_config JSON DEFAULT NULL, zone_config JSON DEFAULT NULL, matching_config JSON DEFAULT NULL, numeric_config JSON DEFAULT NULL, explanation LONGTEXT DEFAULT NULL, points NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL, duel_id INT NOT NULL, source_quiz_id INT DEFAULT NULL, source_question_id INT DEFAULT NULL, INDEX IDX_D3C2FC3058875E (duel_id), INDEX IDX_D3C2FC30CCE3691D (source_quiz_id), INDEX IDX_D3C2FC302258A61F (source_question_id), UNIQUE INDEX quiz_tournament_duel_question_rank (duel_id, `rank`), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_player (id INT AUTO_INCREMENT NOT NULL, rating INT NOT NULL, duels INT NOT NULL, wins INT NOT NULL, draws INT NOT NULL, losses INT NOT NULL, round_exemptions INT NOT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, tournament_id INT NOT NULL, user_id INT NOT NULL, displayed_program_id INT DEFAULT NULL, INDEX IDX_74A789B633D1A3E7 (tournament_id), INDEX IDX_74A789B6A76ED395 (user_id), INDEX IDX_74A789B6B3CAF494 (displayed_program_id), UNIQUE INDEX quiz_tournament_player_unique (tournament_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_round (id INT AUTO_INCREMENT NOT NULL, restricted_group_index SMALLINT DEFAULT NULL, opened_at DATETIME NOT NULL, paired_at DATETIME DEFAULT NULL, cancelled_at DATETIME DEFAULT NULL, tournament_id INT NOT NULL, program_id INT NOT NULL, restricted_option_id INT DEFAULT NULL, restricted_group_batch_id INT DEFAULT NULL, teacher_id INT NOT NULL, exempt_user_id INT DEFAULT NULL, INDEX IDX_18EF1FF533D1A3E7 (tournament_id), INDEX IDX_18EF1FF53EB8070A (program_id), INDEX IDX_18EF1FF5D4D85399 (restricted_option_id), INDEX IDX_18EF1FF5573F08D7 (restricted_group_batch_id), INDEX IDX_18EF1FF541807E1D (teacher_id), INDEX IDX_18EF1FF5FF810D8D (exempt_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_tournament_round_participant (round_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B9F3C673A6005CA0 (round_id), INDEX IDX_B9F3C673A76ED395 (user_id), PRIMARY KEY (round_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_tournament ADD CONSTRAINT FK_F4309FE5B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament ADD CONSTRAINT FK_F4309FE5896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament ADD CONSTRAINT FK_F4309FE5AB159F5 FOREIGN KEY (opened_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_source ADD CONSTRAINT FK_B3348CA0196871F6 FOREIGN KEY (quiz_tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_source ADD CONSTRAINT FK_B3348CA02AFC1C18 FOREIGN KEY (quiz_template_id) REFERENCES quiz_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_section ADD CONSTRAINT FK_32454772196871F6 FOREIGN KEY (quiz_tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_section ADD CONSTRAINT FK_32454772D823E37A FOREIGN KEY (section_id) REFERENCES section (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_track ADD CONSTRAINT FK_BE20D67196871F6 FOREIGN KEY (quiz_tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_track ADD CONSTRAINT FK_BE20D675ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_program ADD CONSTRAINT FK_8DDB4A19196871F6 FOREIGN KEY (quiz_tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_program ADD CONSTRAINT FK_8DDB4A193EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_challenge ADD CONSTRAINT FK_4B8E2E2133D1A3E7 FOREIGN KEY (tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_challenge ADD CONSTRAINT FK_4B8E2E212D521FDF FOREIGN KEY (challenger_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_challenge ADD CONSTRAINT FK_4B8E2E215820179C FOREIGN KEY (challenged_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_challenge ADD CONSTRAINT FK_4B8E2E2158875E FOREIGN KEY (duel_id) REFERENCES quiz_tournament_duel (id)');
        $this->addSql('ALTER TABLE quiz_tournament_duel ADD CONSTRAINT FK_973DAF0D33D1A3E7 FOREIGN KEY (tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_duel ADD CONSTRAINT FK_973DAF0D99C4036B FOREIGN KEY (player_a_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_duel ADD CONSTRAINT FK_973DAF0D8B71AC85 FOREIGN KEY (player_b_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_duel ADD CONSTRAINT FK_973DAF0DA6005CA0 FOREIGN KEY (round_id) REFERENCES quiz_tournament_round (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_duel_answer ADD CONSTRAINT FK_D355CA771E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_tournament_duel_question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_duel_answer ADD CONSTRAINT FK_D355CA7799E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question ADD CONSTRAINT FK_D3C2FC3058875E FOREIGN KEY (duel_id) REFERENCES quiz_tournament_duel (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question ADD CONSTRAINT FK_D3C2FC30CCE3691D FOREIGN KEY (source_quiz_id) REFERENCES quiz_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question ADD CONSTRAINT FK_D3C2FC302258A61F FOREIGN KEY (source_question_id) REFERENCES quiz_question (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_player ADD CONSTRAINT FK_74A789B633D1A3E7 FOREIGN KEY (tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_player ADD CONSTRAINT FK_74A789B6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_player ADD CONSTRAINT FK_74A789B6B3CAF494 FOREIGN KEY (displayed_program_id) REFERENCES program (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF533D1A3E7 FOREIGN KEY (tournament_id) REFERENCES quiz_tournament (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF53EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF5D4D85399 FOREIGN KEY (restricted_option_id) REFERENCES `option` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF5573F08D7 FOREIGN KEY (restricted_group_batch_id) REFERENCES group_batch (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF541807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_round ADD CONSTRAINT FK_18EF1FF5FF810D8D FOREIGN KEY (exempt_user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_round_participant ADD CONSTRAINT FK_B9F3C673A6005CA0 FOREIGN KEY (round_id) REFERENCES quiz_tournament_round (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_round_participant ADD CONSTRAINT FK_B9F3C673A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_tournament DROP FOREIGN KEY FK_F4309FE5B03A8386');
        $this->addSql('ALTER TABLE quiz_tournament DROP FOREIGN KEY FK_F4309FE5896DBBDE');
        $this->addSql('ALTER TABLE quiz_tournament DROP FOREIGN KEY FK_F4309FE5AB159F5');
        $this->addSql('ALTER TABLE quiz_tournament_source DROP FOREIGN KEY FK_B3348CA0196871F6');
        $this->addSql('ALTER TABLE quiz_tournament_source DROP FOREIGN KEY FK_B3348CA02AFC1C18');
        $this->addSql('ALTER TABLE quiz_tournament_section DROP FOREIGN KEY FK_32454772196871F6');
        $this->addSql('ALTER TABLE quiz_tournament_section DROP FOREIGN KEY FK_32454772D823E37A');
        $this->addSql('ALTER TABLE quiz_tournament_track DROP FOREIGN KEY FK_BE20D67196871F6');
        $this->addSql('ALTER TABLE quiz_tournament_track DROP FOREIGN KEY FK_BE20D675ED23C43');
        $this->addSql('ALTER TABLE quiz_tournament_program DROP FOREIGN KEY FK_8DDB4A19196871F6');
        $this->addSql('ALTER TABLE quiz_tournament_program DROP FOREIGN KEY FK_8DDB4A193EB8070A');
        $this->addSql('ALTER TABLE quiz_tournament_challenge DROP FOREIGN KEY FK_4B8E2E2133D1A3E7');
        $this->addSql('ALTER TABLE quiz_tournament_challenge DROP FOREIGN KEY FK_4B8E2E212D521FDF');
        $this->addSql('ALTER TABLE quiz_tournament_challenge DROP FOREIGN KEY FK_4B8E2E215820179C');
        $this->addSql('ALTER TABLE quiz_tournament_challenge DROP FOREIGN KEY FK_4B8E2E2158875E');
        $this->addSql('ALTER TABLE quiz_tournament_duel DROP FOREIGN KEY FK_973DAF0D33D1A3E7');
        $this->addSql('ALTER TABLE quiz_tournament_duel DROP FOREIGN KEY FK_973DAF0D99C4036B');
        $this->addSql('ALTER TABLE quiz_tournament_duel DROP FOREIGN KEY FK_973DAF0D8B71AC85');
        $this->addSql('ALTER TABLE quiz_tournament_duel DROP FOREIGN KEY FK_973DAF0DA6005CA0');
        $this->addSql('ALTER TABLE quiz_tournament_duel_answer DROP FOREIGN KEY FK_D355CA771E27F6BF');
        $this->addSql('ALTER TABLE quiz_tournament_duel_answer DROP FOREIGN KEY FK_D355CA7799E6F5DF');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question DROP FOREIGN KEY FK_D3C2FC3058875E');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question DROP FOREIGN KEY FK_D3C2FC30CCE3691D');
        $this->addSql('ALTER TABLE quiz_tournament_duel_question DROP FOREIGN KEY FK_D3C2FC302258A61F');
        $this->addSql('ALTER TABLE quiz_tournament_player DROP FOREIGN KEY FK_74A789B633D1A3E7');
        $this->addSql('ALTER TABLE quiz_tournament_player DROP FOREIGN KEY FK_74A789B6A76ED395');
        $this->addSql('ALTER TABLE quiz_tournament_player DROP FOREIGN KEY FK_74A789B6B3CAF494');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF533D1A3E7');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF53EB8070A');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF5D4D85399');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF5573F08D7');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF541807E1D');
        $this->addSql('ALTER TABLE quiz_tournament_round DROP FOREIGN KEY FK_18EF1FF5FF810D8D');
        $this->addSql('ALTER TABLE quiz_tournament_round_participant DROP FOREIGN KEY FK_B9F3C673A6005CA0');
        $this->addSql('ALTER TABLE quiz_tournament_round_participant DROP FOREIGN KEY FK_B9F3C673A76ED395');
        $this->addSql('DROP TABLE quiz_tournament');
        $this->addSql('DROP TABLE quiz_tournament_source');
        $this->addSql('DROP TABLE quiz_tournament_section');
        $this->addSql('DROP TABLE quiz_tournament_track');
        $this->addSql('DROP TABLE quiz_tournament_program');
        $this->addSql('DROP TABLE quiz_tournament_challenge');
        $this->addSql('DROP TABLE quiz_tournament_duel');
        $this->addSql('DROP TABLE quiz_tournament_duel_answer');
        $this->addSql('DROP TABLE quiz_tournament_duel_question');
        $this->addSql('DROP TABLE quiz_tournament_player');
        $this->addSql('DROP TABLE quiz_tournament_round');
        $this->addSql('DROP TABLE quiz_tournament_round_participant');
    }
}
