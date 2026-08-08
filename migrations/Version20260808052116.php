<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-question time on top of the quiz's own default: the quiz default becomes nullable (null =
 * no limit at all), and each question carries a mode saying whether it follows that default, lifts
 * the limit for itself, or sets its own count.
 *
 * Existing rows keep their behaviour: default_seconds_per_question keeps its value, and every
 * question starts on 'quiz', i.e. exactly what it did before this column existed.
 *
 * The generated diff also carried an unrelated TINYINT default drift on `assignment`, dropped
 * here - it is a mapping/schema mismatch predating this change, not part of it.
 */
final class Version20260808052116 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Temps par question : mode sur chaque question, défaut du quiz rendu illimitable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_question ADD time_mode VARCHAR(20) DEFAULT \'quiz\' NOT NULL, ADD time_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question ADD time_mode VARCHAR(20) DEFAULT \'quiz\' NOT NULL, ADD time_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_template CHANGE default_seconds_per_question default_seconds_per_question INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // A quiz left unlimited has no number to go back to - 30 is the column's own former
        // default, and the only value that lets the NOT NULL come back.
        $this->addSql('UPDATE quiz_template SET default_seconds_per_question = 30 WHERE default_seconds_per_question IS NULL');
        $this->addSql('ALTER TABLE quiz_template CHANGE default_seconds_per_question default_seconds_per_question INT NOT NULL');
        $this->addSql('ALTER TABLE quiz_instance_question DROP time_mode, DROP time_seconds');
        $this->addSql('ALTER TABLE quiz_question DROP time_mode, DROP time_seconds');
    }
}
