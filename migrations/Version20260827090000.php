<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The campus game's engine - design/validated/gamification.md, lot 1.
 *
 * Six tables and two columns, and the two columns are the ones that matter on the day this ships:
 * `program.game_enabled` is off on every existing formation and off on every formation created
 * afterwards, and `App\Enum\Feature::Game` is absent from the matrix, which falls back on "no". The
 * conjunction is strict, so nothing appears anywhere until both are switched on for the same class.
 *
 * `game_entry` is an append-only journal: no balance is stored, the index is recomputed, and a wrong
 * line is undone by an inverse line pointing at it. That is why there is no unique constraint on the
 * source - a reversal deliberately carries the same one as the line it undoes - and why the index on
 * (source_type, source_id) exists instead: it is what makes a re-read idempotent.
 *
 * `signup_list_registration.attended` is the one field added outside the game's own tables. The
 * design asks for « l'inscription **tenue** », and the entity carried no such thing; it says only
 * that somebody turned up, never that somebody did not.
 *
 * The level wordings are seeded from design/design_handoff_gamification/data/gamification.json,
 * whose visual half is taken over whole. The XP thresholds are **not** seeded: they are common to
 * the whole establishment and live in App\Service\Game\GameLevels.
 *
 * Every DEFAULT below serves its own ALTER and is dropped right after - the PHP property carries it.
 */
final class Version20260827090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campus game engine: ledger, profile, frozen scores, barème, per-program settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_entry (id INT AUTO_INCREMENT NOT NULL, family VARCHAR(20) NOT NULL, rule_code VARCHAR(60) NOT NULL, points INT NOT NULL, occurred_at DATETIME NOT NULL, source_type VARCHAR(60) DEFAULT NULL, source_id INT DEFAULT NULL, reason LONGTEXT DEFAULT NULL, contested_at DATETIME DEFAULT NULL, resolved_at DATETIME DEFAULT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, author_id INT DEFAULT NULL, reversal_of_id INT DEFAULT NULL, INDEX IDX_1912E4FFCB944F1A (student_id), INDEX IDX_1912E4FF3EB8070A (program_id), INDEX IDX_1912E4FFEC8B7ADE (period_id), INDEX IDX_1912E4FFF675F31B (author_id), INDEX IDX_1912E4FF29A0BB4E (reversal_of_id), INDEX idx_game_entry_student_period_family (student_id, period_id, family), INDEX idx_game_entry_program_period (program_id, period_id), INDEX idx_game_entry_source (source_type, source_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_level_label (id INT AUTO_INCREMENT NOT NULL, track VARCHAR(10) NOT NULL, level INT NOT NULL, label VARCHAR(120) NOT NULL, UNIQUE INDEX uniq_game_level_label (track, level), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_period_score (id INT AUTO_INCREMENT NOT NULL, index_value INT NOT NULL, rate_attendance NUMERIC(5, 4) DEFAULT NULL, rate_work NUMERIC(5, 4) DEFAULT NULL, rate_engagement NUMERIC(5, 4) DEFAULT NULL, rate_recognition NUMERIC(5, 4) DEFAULT NULL, `rank` INT DEFAULT NULL, xp_awarded INT NOT NULL, closed_at DATETIME NOT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, INDEX IDX_EDE18B5ACB944F1A (student_id), INDEX IDX_EDE18B5A3EB8070A (program_id), INDEX IDX_EDE18B5AEC8B7ADE (period_id), UNIQUE INDEX uniq_game_period_score (student_id, period_id, program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_profile (id INT AUTO_INCREMENT NOT NULL, xp_total INT NOT NULL, level INT NOT NULL, displayed_title VARCHAR(120) DEFAULT NULL, discreet TINYINT NOT NULL, discreet_since DATETIME DEFAULT NULL, discreet_until DATETIME DEFAULT NULL, student_id INT NOT NULL, UNIQUE INDEX uniq_game_profile_student (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_rule (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, points INT NOT NULL, weekly_cap INT DEFAULT NULL, enabled TINYINT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, INDEX IDX_ADCDC0E03EB8070A (program_id), INDEX IDX_ADCDC0E0EC8B7ADE (period_id), UNIQUE INDEX uniq_game_rule (program_id, period_id, code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_program_settings (id INT AUTO_INCREMENT NOT NULL, weight_attendance INT NOT NULL, weight_work INT NOT NULL, weight_engagement INT NOT NULL, weight_recognition INT NOT NULL, engagement_cap INT NOT NULL, recognition_cap INT NOT NULL, attendance_step VARCHAR(10) NOT NULL, attendance_streak_cap INT NOT NULL, threshold_bronze INT NOT NULL, threshold_silver INT NOT NULL, threshold_gold INT NOT NULL, gesture_envelope INT NOT NULL, gesture_net_bound INT NOT NULL, team_threshold INT NOT NULL, team_mode VARCHAR(10) NOT NULL, ranking_enabled TINYINT NOT NULL, alias_enabled TINYINT NOT NULL, malus_enabled TINYINT NOT NULL, program_id INT NOT NULL, UNIQUE INDEX uniq_game_program_settings_program (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_entry ADD CONSTRAINT FK_1912E4FFCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_entry ADD CONSTRAINT FK_1912E4FF3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_entry ADD CONSTRAINT FK_1912E4FFEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_entry ADD CONSTRAINT FK_1912E4FFF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_entry ADD CONSTRAINT FK_1912E4FF29A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES game_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_period_score ADD CONSTRAINT FK_EDE18B5ACB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_period_score ADD CONSTRAINT FK_EDE18B5A3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_period_score ADD CONSTRAINT FK_EDE18B5AEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_profile ADD CONSTRAINT FK_1495D520CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_rule ADD CONSTRAINT FK_ADCDC0E03EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_rule ADD CONSTRAINT FK_ADCDC0E0EC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_program_settings ADD CONSTRAINT FK_5A619BEC3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE signup_list_registration ADD attended TINYINT DEFAULT 0 NOT NULL, ADD attended_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD game_enabled TINYINT DEFAULT 0 NOT NULL, ADD game_track VARCHAR(10) DEFAULT NULL');

        // The role matrix gains its eight rows, all off. Written out rather than derived, like
        // every other seeding migration here: a migration must keep meaning what it meant, even if
        // the catalogue's defaults move afterwards. Off for everybody is the whole point - the game
        // appears nowhere until an administrator switches it on for a role *and* a formation
        // declares itself (§4, decision 1).
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_STUDENT', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_TEACHER', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_STAFF', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_STAFF-LEAD', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_TUTOR', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_SUPPORT-TECH', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_ECO', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('game', 'ROLE_EXTERNAL', 0)");

        // The six levels x four filières of the handoff, reproduced without invention. An admin
        // edits them from /settings/game/levels; a missing row falls back on generic wording.
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 1, 'Stagiaire « Hello World »')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 1, 'Stagiaire du help desk')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 1, 'Stagiaire de la saisie')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 1, 'Stagiaire de l’accueil')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 2, 'Apprenti·e du code')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 2, 'Apprenti·e câbleur·se')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 2, 'Apprenti·e des comptes')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 2, 'Apprenti·e vendeur·se')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 3, 'Chasseur·se de bugs')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 3, 'Chasseur·se de pannes')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 3, 'Chasseur·se d’écarts')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 3, 'Pro du rayon')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 4, 'Artisan·e du web')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 4, 'Gardien·ne des serveurs')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 4, 'Gardien·ne du grand livre')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 4, 'As du conseil')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 5, 'Chef·fe de projet étudiant')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 5, 'Sentinelle du réseau')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 5, 'As de la clôture')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 5, 'Négociateur·rice hors pair')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SLAM', 6, 'Dév. junior')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('SISR', 6, 'Admin junior')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('CG', 6, 'Comptable junior')");
        $this->addSql("INSERT INTO game_level_label (track, level, label) VALUES ('MCO', 6, 'Manager junior')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program DROP game_enabled, DROP game_track');
        $this->addSql('ALTER TABLE signup_list_registration DROP attended, DROP attended_at');
        $this->addSql('ALTER TABLE game_entry DROP FOREIGN KEY FK_1912E4FF29A0BB4E');
        $this->addSql('DROP TABLE game_entry');
        $this->addSql('DROP TABLE game_level_label');
        $this->addSql('DROP TABLE game_period_score');
        $this->addSql('DROP TABLE game_profile');
        $this->addSql('DROP TABLE game_rule');
        $this->addSql('DROP TABLE game_program_settings');
    }
}
