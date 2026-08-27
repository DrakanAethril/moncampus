<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nothing in the campus game is conditioned on an evaluation period any more, and the teacher's
 * gesture loses its quota.
 *
 * **`game_entry` drops `period_id`**, which is the change the rest follows from. A period is a range
 * of dates and an entry carries the date it happened on, so which period it counts towards is a
 * *reading* - App\Repository\GameEntryRepository sums on `occurred_at` between two bounds - and never
 * a condition on writing one. Before this, a teacher could not thank a student on a day the calendar
 * had not planned for; now the gesture is written, and it counts towards an index only if a period
 * happens to cover it. The two new indexes replace the two the foreign key carried.
 *
 * **`game_rule` drops it too**: retuning a rule is a decision about a class, not about a term. What a
 * closed period keeps is its *result*, frozen once in `game_period_score` and never recomputed, so
 * moving a value today still cannot move January's ranking.
 *
 * **`engagement_declaration` drops it**: a student declares what they did on the day they did it.
 * The one rule that used to read « once per period », the mandate, becomes once per formation - a
 * mandate is held for a year, not for a term.
 *
 * **`game_program_settings` drops the gesture envelope and the ±60 net bound.** Both were the same
 * idea twice - a quota placed between a teacher and their own judgement - and both were removed on
 * request. What still governs a gesture is not a counter: a mandatory motive read by the student, a
 * malus confined to dress or behaviour by the schema itself, seven days to contest, and a withdrawal
 * that writes an inverse line rather than erasing anything.
 *
 * Nothing is migrated: the game has never been switched on in production, so every table touched
 * here is empty by construction.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campus game: the period becomes a reading, and the gesture loses its per-teacher quota';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_entry DROP FOREIGN KEY FK_1912E4FFEC8B7ADE');
        $this->addSql('DROP INDEX IDX_1912E4FFEC8B7ADE ON game_entry');
        $this->addSql('DROP INDEX idx_game_entry_student_period_family ON game_entry');
        $this->addSql('DROP INDEX idx_game_entry_program_period ON game_entry');
        $this->addSql('ALTER TABLE game_entry DROP period_id');
        $this->addSql('CREATE INDEX idx_game_entry_student_family ON game_entry (student_id, family, occurred_at)');
        $this->addSql('CREATE INDEX idx_game_entry_program_occurred ON game_entry (program_id, occurred_at)');

        $this->addSql('ALTER TABLE game_rule DROP FOREIGN KEY FK_ADCDC0E0EC8B7ADE');
        $this->addSql('DROP INDEX uniq_game_rule ON game_rule');
        $this->addSql('DROP INDEX IDX_ADCDC0E0EC8B7ADE ON game_rule');
        $this->addSql('ALTER TABLE game_rule DROP period_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_rule ON game_rule (program_id, code)');

        $this->addSql('ALTER TABLE engagement_declaration DROP FOREIGN KEY FK_C165F0BFEC8B7ADE');
        $this->addSql('DROP INDEX IDX_C165F0BFEC8B7ADE ON engagement_declaration');
        $this->addSql('DROP INDEX idx_engagement_program_period_state ON engagement_declaration');
        $this->addSql('ALTER TABLE engagement_declaration DROP period_id');
        $this->addSql('CREATE INDEX idx_engagement_program_state ON engagement_declaration (program_id, state)');

        $this->addSql('ALTER TABLE game_program_settings DROP gesture_envelope, DROP gesture_net_bound');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_program_settings ADD gesture_envelope INT NOT NULL, ADD gesture_net_bound INT NOT NULL');
        $this->addSql('ALTER TABLE engagement_declaration ADD period_id INT NOT NULL');
        $this->addSql('ALTER TABLE game_rule ADD period_id INT NOT NULL');
        $this->addSql('ALTER TABLE game_entry ADD period_id INT NOT NULL');
    }
}
