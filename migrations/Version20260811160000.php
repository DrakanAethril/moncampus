<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Zone/Légende question types (étude 2026-08-11): the definition of both new types lives in one
 * JSON column per question table - same contract as blanks_config - and the student's clicks/
 * placements land in a JSON column of the attempt answer, since a clicked zone id has no answer
 * row to point at.
 */
final class Version20260811160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : types Zone et Légende (config JSON des questions, réponses de zones des tentatives)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD zone_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD zone_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD zone_responses JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP zone_config');
        $this->addSql('ALTER TABLE quiz_instance_question DROP zone_config');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP zone_responses');
    }
}
