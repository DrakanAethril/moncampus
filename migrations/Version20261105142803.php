<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The live concours plays every type a Kahoot carries (2026-11-05):
 *
 * - `quiz_live_answer.response` holds the whole answer (the propositions tapped, an order, a number,
 *   a word) where `selected_answer_id` held one proposition. No live concours was ever run in
 *   production, so nothing is carried over.
 * - the run of a question follows Kahoot's - statement alone, answers, results, scoreboard - and the
 *   step once called `reveal` is now `results`; a session caught in it (dev only) follows.
 */
final class Version20261105142803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Live concours: quiz_live_answer.response replaces selected_answer_id, status reveal -> results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_live_answer DROP FOREIGN KEY `FK_96ADEF51F24C5BEC`');
        $this->addSql('DROP INDEX IDX_96ADEF51F24C5BEC ON quiz_live_answer');
        $this->addSql('ALTER TABLE quiz_live_answer ADD response JSON DEFAULT NULL, DROP selected_answer_id');
        $this->addSql("UPDATE quiz_live_session SET status = 'results' WHERE status = 'reveal'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE quiz_live_session SET status = 'reveal' WHERE status IN ('results', 'reading', 'scoreboard')");
        $this->addSql('ALTER TABLE quiz_live_answer ADD selected_answer_id INT DEFAULT NULL, DROP response');
        $this->addSql('ALTER TABLE quiz_live_answer ADD CONSTRAINT `FK_96ADEF51F24C5BEC` FOREIGN KEY (selected_answer_id) REFERENCES quiz_instance_answer (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_96ADEF51F24C5BEC ON quiz_live_answer (selected_answer_id)');
    }
}
