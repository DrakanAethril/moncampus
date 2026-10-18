<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The serious games' tables (design/validated/serious-games.md §4).
 *
 * The games themselves are data (`serious_game`, written by `app:serious-games:sync` from
 * `config/serious_games/`), switched on per role beside the module's feature
 * (`serious_game_role_setting`); their question templates are versioned rows (`serious_game_template`)
 * so a question served once can be replayed from its seed. What a player acquired is written by the
 * server alone (`serious_game_unit_state`, `serious_game_comet`, `serious_game_reward_grant`), from the
 * sessions and answers it corrected - and the statement of a question is never stored.
 */
final class Version20261018072850 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the serious games tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game (
              id INT AUTO_INCREMENT NOT NULL,
              slug VARCHAR(64) NOT NULL,
              title VARCHAR(120) NOT NULL,
              pitch VARCHAR(255) NOT NULL,
              subjects JSON NOT NULL,
              levels JSON NOT NULL,
              unit_label VARCHAR(60) NOT NULL,
              units_total INT NOT NULL,
              answer_kinds JSON NOT NULL,
              published TINYINT NOT NULL,
              version VARCHAR(20) NOT NULL,
              manifest JSON NOT NULL,
              UNIQUE INDEX uniq_serious_game_slug (slug),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_answer (
              id INT AUTO_INCREMENT NOT NULL,
              position SMALLINT NOT NULL,
              unit_code VARCHAR(40) NOT NULL,
              template_version INT NOT NULL,
              seed BIGINT NOT NULL,
              input VARCHAR(500) DEFAULT NULL,
              ok TINYINT DEFAULT NULL,
              misconception VARCHAR(80) DEFAULT NULL,
              dont_know TINYINT NOT NULL,
              ms INT DEFAULT NULL,
              served_at DATETIME NOT NULL,
              answered_at DATETIME DEFAULT NULL,
              session_id INT NOT NULL,
              INDEX IDX_ABD742CA613FECDF (session_id),
              INDEX idx_serious_game_answer_misconception (misconception),
              UNIQUE INDEX uniq_serious_game_answer_position (session_id, position),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_avatar (
              id INT AUTO_INCREMENT NOT NULL,
              config JSON NOT NULL,
              config_version SMALLINT NOT NULL,
              updated_at DATETIME NOT NULL,
              user_id INT NOT NULL,
              UNIQUE INDEX uniq_serious_game_avatar_user (user_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_comet (
              id INT AUTO_INCREMENT NOT NULL,
              misconception VARCHAR(80) NOT NULL,
              unit_code VARCHAR(40) NOT NULL,
              state VARCHAR(20) NOT NULL,
              appeared_at DATETIME NOT NULL,
              solved_at DATETIME DEFAULT NULL,
              attempts SMALLINT NOT NULL,
              player_id INT NOT NULL,
              game_id INT NOT NULL,
              INDEX IDX_CF95092599E6F5DF (player_id),
              INDEX IDX_CF950925E48FD905 (game_id),
              INDEX idx_serious_game_comet_player (player_id, game_id, state),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_progress (
              id INT AUTO_INCREMENT NOT NULL,
              data JSON NOT NULL,
              last_played_at DATETIME NOT NULL,
              player_id INT NOT NULL,
              game_id INT NOT NULL,
              INDEX IDX_F53CE45D99E6F5DF (player_id),
              INDEX IDX_F53CE45DE48FD905 (game_id),
              INDEX idx_serious_game_progress_played (last_played_at),
              UNIQUE INDEX uniq_serious_game_progress (player_id, game_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_reward_grant (
              id INT AUTO_INCREMENT NOT NULL,
              reward_code VARCHAR(80) NOT NULL,
              finish VARCHAR(20) DEFAULT NULL,
              granted_at DATETIME NOT NULL,
              finish_raised_at DATETIME DEFAULT NULL,
              student_id INT NOT NULL,
              INDEX IDX_43353D85CB944F1A (student_id),
              UNIQUE INDEX uniq_serious_game_reward_grant (student_id, reward_code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_role_setting (
              id INT AUTO_INCREMENT NOT NULL,
              role VARCHAR(40) NOT NULL,
              enabled TINYINT NOT NULL,
              game_id INT NOT NULL,
              INDEX IDX_CE551DF6E48FD905 (game_id),
              UNIQUE INDEX uniq_serious_game_role (game_id, role),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_session (
              id INT AUTO_INCREMENT NOT NULL,
              mode VARCHAR(20) NOT NULL,
              unit_code VARCHAR(40) DEFAULT NULL,
              misconception VARCHAR(80) DEFAULT NULL,
              size SMALLINT NOT NULL,
              plan JSON NOT NULL,
              started_at DATETIME NOT NULL,
              ended_at DATETIME DEFAULT NULL,
              score SMALLINT DEFAULT NULL,
              time_limit_seconds INT DEFAULT NULL,
              timed_out TINYINT NOT NULL,
              player_id INT NOT NULL,
              game_id INT NOT NULL,
              INDEX IDX_E080203599E6F5DF (player_id),
              INDEX IDX_E0802035E48FD905 (game_id),
              INDEX idx_serious_game_session_player (player_id, game_id, started_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_template (
              id INT AUTO_INCREMENT NOT NULL,
              unit_code VARCHAR(40) NOT NULL,
              version INT NOT NULL,
              status VARCHAR(20) NOT NULL,
              definition JSON NOT NULL,
              note LONGTEXT DEFAULT NULL,
              bench_report JSON DEFAULT NULL,
              created_at DATETIME NOT NULL,
              validated_at DATETIME DEFAULT NULL,
              game_id INT NOT NULL,
              author_id INT DEFAULT NULL,
              validated_by_id INT DEFAULT NULL,
              INDEX IDX_405D0998E48FD905 (game_id),
              INDEX IDX_405D0998F675F31B (author_id),
              INDEX IDX_405D0998C69DE5E5 (validated_by_id),
              UNIQUE INDEX uniq_serious_game_template_version (game_id, unit_code, version),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE serious_game_unit_state (
              id INT AUTO_INCREMENT NOT NULL,
              unit_code VARCHAR(40) NOT NULL,
              best SMALLINT DEFAULT NULL,
              series_count INT NOT NULL,
              first_lit_at DATETIME DEFAULT NULL,
              player_id INT NOT NULL,
              game_id INT NOT NULL,
              INDEX IDX_7543050699E6F5DF (player_id),
              INDEX IDX_75430506E48FD905 (game_id),
              UNIQUE INDEX uniq_serious_game_unit_state (player_id, game_id, unit_code),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_answer
            ADD
              CONSTRAINT FK_ABD742CA613FECDF FOREIGN KEY (session_id) REFERENCES serious_game_session (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_avatar
            ADD
              CONSTRAINT FK_677D7AC0A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_comet
            ADD
              CONSTRAINT FK_CF95092599E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_comet
            ADD
              CONSTRAINT FK_CF950925E48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_progress
            ADD
              CONSTRAINT FK_F53CE45D99E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_progress
            ADD
              CONSTRAINT FK_F53CE45DE48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_reward_grant
            ADD
              CONSTRAINT FK_43353D85CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_role_setting
            ADD
              CONSTRAINT FK_CE551DF6E48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_session
            ADD
              CONSTRAINT FK_E080203599E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_session
            ADD
              CONSTRAINT FK_E0802035E48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_template
            ADD
              CONSTRAINT FK_405D0998E48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_template
            ADD
              CONSTRAINT FK_405D0998F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_template
            ADD
              CONSTRAINT FK_405D0998C69DE5E5 FOREIGN KEY (validated_by_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_unit_state
            ADD
              CONSTRAINT FK_7543050699E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              serious_game_unit_state
            ADD
              CONSTRAINT FK_75430506E48FD905 FOREIGN KEY (game_id) REFERENCES serious_game (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game_answer DROP FOREIGN KEY FK_ABD742CA613FECDF');
        $this->addSql('ALTER TABLE serious_game_avatar DROP FOREIGN KEY FK_677D7AC0A76ED395');
        $this->addSql('ALTER TABLE serious_game_comet DROP FOREIGN KEY FK_CF95092599E6F5DF');
        $this->addSql('ALTER TABLE serious_game_comet DROP FOREIGN KEY FK_CF950925E48FD905');
        $this->addSql('ALTER TABLE serious_game_progress DROP FOREIGN KEY FK_F53CE45D99E6F5DF');
        $this->addSql('ALTER TABLE serious_game_progress DROP FOREIGN KEY FK_F53CE45DE48FD905');
        $this->addSql('ALTER TABLE serious_game_reward_grant DROP FOREIGN KEY FK_43353D85CB944F1A');
        $this->addSql('ALTER TABLE serious_game_role_setting DROP FOREIGN KEY FK_CE551DF6E48FD905');
        $this->addSql('ALTER TABLE serious_game_session DROP FOREIGN KEY FK_E080203599E6F5DF');
        $this->addSql('ALTER TABLE serious_game_session DROP FOREIGN KEY FK_E0802035E48FD905');
        $this->addSql('ALTER TABLE serious_game_template DROP FOREIGN KEY FK_405D0998E48FD905');
        $this->addSql('ALTER TABLE serious_game_template DROP FOREIGN KEY FK_405D0998F675F31B');
        $this->addSql('ALTER TABLE serious_game_template DROP FOREIGN KEY FK_405D0998C69DE5E5');
        $this->addSql('ALTER TABLE serious_game_unit_state DROP FOREIGN KEY FK_7543050699E6F5DF');
        $this->addSql('ALTER TABLE serious_game_unit_state DROP FOREIGN KEY FK_75430506E48FD905');
        $this->addSql('DROP TABLE serious_game');
        $this->addSql('DROP TABLE serious_game_answer');
        $this->addSql('DROP TABLE serious_game_avatar');
        $this->addSql('DROP TABLE serious_game_comet');
        $this->addSql('DROP TABLE serious_game_progress');
        $this->addSql('DROP TABLE serious_game_reward_grant');
        $this->addSql('DROP TABLE serious_game_role_setting');
        $this->addSql('DROP TABLE serious_game_session');
        $this->addSql('DROP TABLE serious_game_template');
        $this->addSql('DROP TABLE serious_game_unit_state');
    }
}
