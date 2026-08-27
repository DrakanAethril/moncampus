<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The month becomes the scoring window, the six levels get their six frames, and the joker goes.
 *
 * **`game_month_score` replaces `game_period_score`.** Points are credited on the day they are
 * earned and counted in the month that day falls in: a month is the same for everybody, needs no
 * setting up, and nobody has to ask which one they are in. `month_key` is `YYYY-MM`, which is what
 * makes a plain string column sort a calendar. `bonus_awarded` is the podium of that month - 20, 10
 * and 5 to its first three - where `xp_awarded` used to be a formula over a number of periods.
 *
 * **`game_profile.xp_total` becomes `total_points`**: what a level is made of is everything a
 * student has ever earned, across every formation they pass through. Changing formation therefore
 * starts them at the level those points already give them, and only the *wording* of that level
 * changes with the filière.
 *
 * **`reward_item.automatic_threshold` becomes `level`.** There were three frames - bronze, silver,
 * gold - granted on the index of a period, sitting next to six levels granted on points; read
 * together they looked like an arithmetic mistake, and they were the same mistake twice. One frame
 * per level, six of them, granted on the running total. The index's own tiers stay what they always
 * were underneath: a badge on the ranking, computed from the thresholds, never an object in the
 * catalogue.
 *
 * **The joker is removed from the catalogue.** It was the one reward whose rule the application
 * could not enforce - « de droit sur un travail à rendre, et n'existe pas sur une évaluation notée »
 * is a sentence only a human can apply - so it lived as a consumable nobody could refuse and nobody
 * could check.
 *
 * `game_alias` and `game_team_set` lose their period: the monthly and yearly rankings share one
 * board, and a pseudonym or a team that changed between them would make the year unreadable.
 *
 * Nothing is migrated: the game has never been switched on in production, so every table touched
 * here is empty by construction.
 */
final class Version20260828160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Monthly scoring, six level frames, points kept across a whole schooling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_month_score (id INT AUTO_INCREMENT NOT NULL, month_key VARCHAR(7) NOT NULL, index_value INT NOT NULL, rate_attendance NUMERIC(5, 4) DEFAULT NULL, rate_work NUMERIC(5, 4) DEFAULT NULL, rate_engagement NUMERIC(5, 4) DEFAULT NULL, rate_recognition NUMERIC(5, 4) DEFAULT NULL, `rank` INT DEFAULT NULL, bonus_awarded INT NOT NULL, closed_at DATETIME NOT NULL, student_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_3FFCA9CCB944F1A (student_id), INDEX IDX_3FFCA9C3EB8070A (program_id), UNIQUE INDEX uniq_game_month_score (student_id, month_key, program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_month_score ADD CONSTRAINT FK_3FFCA9CCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_month_score ADD CONSTRAINT FK_3FFCA9C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_period_score DROP FOREIGN KEY FK_EDE18B5A3EB8070A');
        $this->addSql('ALTER TABLE game_period_score DROP FOREIGN KEY FK_EDE18B5ACB944F1A');
        $this->addSql('ALTER TABLE game_period_score DROP FOREIGN KEY FK_EDE18B5AEC8B7ADE');
        $this->addSql('DROP TABLE game_period_score');

        $this->addSql('ALTER TABLE game_alias DROP FOREIGN KEY FK_D35F121BEC8B7ADE');
        $this->addSql('DROP INDEX IDX_D35F121BEC8B7ADE ON game_alias');
        $this->addSql('DROP INDEX uniq_game_alias_figure ON game_alias');
        $this->addSql('DROP INDEX uniq_game_alias_student ON game_alias');
        $this->addSql('ALTER TABLE game_alias DROP period_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_alias_figure ON game_alias (program_id, figure_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_alias_student ON game_alias (student_id, program_id)');

        $this->addSql('ALTER TABLE game_team_set DROP FOREIGN KEY FK_17C3505EEC8B7ADE');
        $this->addSql('DROP INDEX IDX_17C3505EEC8B7ADE ON game_team_set');
        $this->addSql('DROP INDEX uniq_game_team_set ON game_team_set');
        $this->addSql('ALTER TABLE game_team_set DROP period_id');
        // The unique index on program_id is created first: it is what the foreign key falls back on
        // once IDX_17C3505E3EB8070A goes, and MySQL refuses to drop the last index a FK can use.
        $this->addSql('CREATE UNIQUE INDEX uniq_game_team_set ON game_team_set (program_id)');
        $this->addSql('DROP INDEX IDX_17C3505E3EB8070A ON game_team_set');

        $this->addSql('ALTER TABLE game_profile CHANGE xp_total total_points INT NOT NULL');
        $this->addSql('ALTER TABLE game_program_settings ADD ranked_months JSON NOT NULL');
        $this->addSql("UPDATE game_program_settings SET ranked_months = '[1,2,3,4,5,6,7,8,9,10,11,12]'");

        $this->addSql('ALTER TABLE reward_item CHANGE automatic_threshold level INT DEFAULT NULL');

        // A grant no longer belongs to an evaluation period: it carries its own date, and the month
        // that date falls in is the only window the game has left.
        $this->addSql('ALTER TABLE reward_grant DROP FOREIGN KEY FK_AED93D10EC8B7ADE');
        $this->addSql('DROP INDEX idx_reward_grant_period ON reward_grant');
        $this->addSql('CREATE INDEX idx_reward_grant_program ON reward_grant (program_id, granted_at)');
        $this->addSql('ALTER TABLE reward_grant DROP period_id');

        // The three index tiers and the joker go; six level frames arrive, one per level.
        $this->addSql("DELETE FROM reward_grant WHERE item_id IN (SELECT id FROM reward_item WHERE program_id IS NULL AND tier_code IN ('bronze', 'silver', 'gold'))");
        $this->addSql("DELETE FROM reward_item WHERE program_id IS NULL AND tier_code IN ('bronze', 'silver', 'gold')");
        $this->addSql("DELETE FROM reward_grant WHERE item_id IN (SELECT id FROM reward_item WHERE program_id IS NULL AND label = 'Joker 24 h')");
        $this->addSql("DELETE FROM reward_item WHERE program_id IS NULL AND label = 'Joker 24 h'");
        $this->addSql("UPDATE reward_item SET description = 'Octroyé à la tête de classe, et mis à l''honneur à la remise des bulletins.' WHERE program_id IS NULL AND tier_code = 'trophy'");

        foreach ([
            [1, '🔩', 'Cadre acier', 'Le premier cadre, dès le premier point compté.'],
            [2, '🔵', 'Cadre bleu clair', 'Ouvert à 300 points de scolarité.'],
            [3, '🔷', 'Cadre bleu campus', 'Ouvert à 700 points de scolarité.'],
            [4, '🟢', 'Cadre émeraude', 'Ouvert à 1 200 points de scolarité.'],
            [5, '🟡', 'Cadre or', 'Ouvert à 1 800 points de scolarité.'],
            [6, '🏆', 'Cadre légendaire', 'Ouvert à 2 500 points de scolarité. Le cadre du profil junior.'],
        ] as [$level, $icon, $label, $description]) {
            $this->addSql(\sprintf(
                "INSERT INTO reward_item (program_id, label, description, nature, scope, icon, level, quantity, active, tier_code) VALUES (NULL, '%s', '%s', 'symbolic', 'student', '%s', %d, NULL, 1, NULL)",
                $label,
                $description,
                $icon,
                $level,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM reward_grant WHERE item_id IN (SELECT id FROM reward_item WHERE level IS NOT NULL)");
        $this->addSql('DELETE FROM reward_item WHERE level IS NOT NULL');
        $this->addSql('ALTER TABLE reward_item CHANGE level automatic_threshold INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reward_grant ADD period_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reward_grant ADD CONSTRAINT FK_AED93D10EC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('DROP INDEX idx_reward_grant_program ON reward_grant');
        $this->addSql('CREATE INDEX idx_reward_grant_period ON reward_grant (program_id, period_id)');
        $this->addSql('ALTER TABLE game_program_settings DROP ranked_months');
        $this->addSql('ALTER TABLE game_profile CHANGE total_points xp_total INT NOT NULL');
        $this->addSql('ALTER TABLE game_team_set ADD period_id INT NOT NULL');
        $this->addSql('ALTER TABLE game_alias ADD period_id INT NOT NULL');
        $this->addSql('DROP TABLE game_month_score');
    }
}
