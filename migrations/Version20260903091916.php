<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `quiz_instance.correction_visible`: whether the student reads their own copy question by question
 * once it is handed in, the very breakdown the teacher gets from « Voir la copie ».
 *
 * `DEFAULT 1`, so every quiz already launched starts showing its correction - which is the point.
 * An évaluation used to end on a screen carrying a percentage and nothing else, and an
 * entraînement's correction has been unconditional since the quizzes shipped: on for everybody is
 * both the new rule and what the existing rows already meant.
 *
 * The correction still waits for the score - a per-question ✓/✕ is the mark by another route - so
 * this column alone does not publish anything a teacher deferred (QuizInstance::isCorrectionReadable()).
 */
final class Version20260903091916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'quiz_instance.correction_visible: the student reads their own corrected copy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance ADD correction_visible TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance DROP correction_visible');
    }
}
