<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Numérique / Calculée question types (2026-08-11): same shape as the three config columns before
 * it - the whole definition (expected value or formula, variables, tolerance, unit) in one JSON
 * column per question table, and the student's answer in a JSON column of the attempt answer, which
 * for a calculée also holds the values that student was drawn.
 */
final class Version20260811213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : types Numérique et Calculée (config JSON des questions, réponse et tirage des tentatives)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD numeric_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD numeric_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD numeric_response JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP numeric_config');
        $this->addSql('ALTER TABLE quiz_instance_question DROP numeric_config');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP numeric_response');
    }
}
