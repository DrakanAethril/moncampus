<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New « Texte à trous » question type (design_handoff_quiz, screens 2a-2d).
 *
 * The whole definition of a fill-in-the-blanks question fits in `blanks_config`: the text itself
 * stays in `label` (the blanks are written « ... » in it), and the JSON carries the mode, the
 * variants accepted per blank, the decoys and the two tolerances. No `quiz_answer` row is created for
 * this type.
 *
 * Migration of the existing data: it is the only type to be graded partially, hence
 * `quiz_attempt_answer.score` and the move of `quiz_attempt.correct_count` to decimal. Attempts
 * already finished keep exactly their score (an integer stays an integer in NUMERIC(7,2)), and their
 * answers receive the 1/0 score following from `is_correct` — without that backfill, the sum of the
 * scores of an old attempt recomputed would be 0.
 */
final class Version20260804050407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Question « texte à trous » : définition JSON, réponses par trou et notation partielle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD blanks_config JSON DEFAULT NULL, ADD points NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD blanks_config JSON DEFAULT NULL, ADD points NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD score NUMERIC(5, 2) DEFAULT NULL, ADD blank_responses JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt CHANGE correct_count correct_count NUMERIC(7, 2) DEFAULT NULL');

        $this->addSql('UPDATE quiz_attempt_answer SET score = IF(is_correct = 1, 1.00, 0.00) WHERE is_correct IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP blanks_config, DROP points');
        $this->addSql('ALTER TABLE quiz_instance_question DROP blanks_config, DROP points');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP score, DROP blank_responses');
        // Any partial scores are truncated: NUMERIC -> INT cannot keep them.
        $this->addSql('ALTER TABLE quiz_attempt CHANGE correct_count correct_count INT DEFAULT NULL');
    }
}
