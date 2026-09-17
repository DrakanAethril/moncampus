<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A detailed barème gains a bonus band and a malus band: EvaluationRubricSection.kind.
 *
 * Every existing band is a standard one, which the DEFAULT writes during the ALTER - nothing on any
 * carnet moves the day this ships. The DEFAULT stays declared on the mapping too, otherwise
 * doctrine:schema:validate reports the drift on every run.
 *
 * Nothing is done to the questions themselves: a bonus item is an ordinary
 * evaluation_rubric_question, and so is the grade_rubric_answer that records what a student was
 * awarded on it. Only the band it hangs from says which way its points move the grade.
 */
final class Version20260917180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Barème détaillé : une section porte son genre (standard, bonus, malus)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE evaluation_rubric_section ADD kind VARCHAR(20) DEFAULT 'standard' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_rubric_section DROP kind');
    }
}
