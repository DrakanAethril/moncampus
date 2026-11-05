<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The quiz tournament's duels give more time (design/validated/quiz-tournament.md §5.2):
 *
 * - 30 s per question and 40 s for a QCM à réponses multiples, ten seconds more than the 20 / 30 s
 *   the tournaments were created with. A tournament still on the old defaults moves with them - the
 *   time fields can no longer be changed on screen once a duel is played; one an administrator set
 *   otherwise keeps its own. Duels already drawn keep the limits they were frozen with.
 * - each player's « ajout de temps » (an accommodation) frozen on the duel at its start;
 * - `quiz_tournament_duel_draft`: what a player ticked or typed without validating, which becomes
 *   their answer when their time runs out.
 */
final class Version20261105093209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz tournament: 30 / 40 s per question, extra time per player on the duel, quiz_tournament_duel_draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_tournament_duel_draft (id INT AUTO_INCREMENT NOT NULL, selected_choice_ids JSON NOT NULL, numeric_value DOUBLE PRECISION DEFAULT NULL, numeric_unit VARCHAR(50) DEFAULT NULL, saved_at DATETIME NOT NULL, question_id INT NOT NULL, player_id INT NOT NULL, INDEX IDX_78B0EF981E27F6BF (question_id), INDEX IDX_78B0EF9899E6F5DF (player_id), UNIQUE INDEX quiz_tournament_duel_draft_unique (question_id, player_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_tournament_duel_draft ADD CONSTRAINT FK_78B0EF981E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_tournament_duel_question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_tournament_duel_draft ADD CONSTRAINT FK_78B0EF9899E6F5DF FOREIGN KEY (player_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_tournament_duel ADD extra_time_percent_a NUMERIC(5, 2) DEFAULT NULL, ADD extra_time_percent_b NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('UPDATE quiz_tournament SET seconds_per_question = 30 WHERE seconds_per_question = 20');
        $this->addSql('UPDATE quiz_tournament SET seconds_per_multi_question = 40 WHERE seconds_per_multi_question = 30');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_tournament_duel_draft DROP FOREIGN KEY FK_78B0EF981E27F6BF');
        $this->addSql('ALTER TABLE quiz_tournament_duel_draft DROP FOREIGN KEY FK_78B0EF9899E6F5DF');
        $this->addSql('DROP TABLE quiz_tournament_duel_draft');
        $this->addSql('ALTER TABLE quiz_tournament_duel DROP extra_time_percent_a, DROP extra_time_percent_b');
        $this->addSql('UPDATE quiz_tournament SET seconds_per_question = 20 WHERE seconds_per_question = 30');
        $this->addSql('UPDATE quiz_tournament SET seconds_per_multi_question = 30 WHERE seconds_per_multi_question = 40');
    }
}
