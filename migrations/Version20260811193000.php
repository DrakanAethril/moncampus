<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Apparier question type (2026-08-11): the same shape as the zones migration one type over - the
 * whole definition (the two columns, their pairs, the distractors, the per-pair feedback) in one
 * JSON column per question table, and the student's associations in a JSON column of the attempt
 * answer, since a picked pair has no answer row to point at.
 */
final class Version20260811193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : type Apparier (config JSON des questions, associations des tentatives)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD matching_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD matching_config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD matching_responses JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question DROP matching_config');
        $this->addSql('ALTER TABLE quiz_instance_question DROP matching_config');
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP matching_responses');
    }
}
