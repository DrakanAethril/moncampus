<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The server-side stopwatch of a quiz passation: three columns on the answer rows.
 *
 * `served_at` is the half of the measurement that was missing - `answered_at` alone is an arrival
 * time with no departure. It is written once and only once, so a reload no longer hands out a fresh
 * per-question budget; `display_count` counts those reloads instead, which is a signal of its own.
 * `elapsed_ms` is the subtraction, frozen at the answer.
 *
 * Always on, in entraînement as in évaluation, and unrelated to the mode contrôle that will read it:
 * how long a class spends on each question is a pedagogical reading first.
 *
 * The `DEFAULT 0` on `display_count` serves the ALTER alone and is dropped right after - the PHP
 * property carries the default, and a DEFAULT left in the schema makes `doctrine:schema:validate`
 * diverge on every run.
 */
final class Version20260826080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-question timing on quiz answer rows: served_at, elapsed_ms, display_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempt_answer ADD served_at DATETIME DEFAULT NULL, ADD elapsed_ms INT UNSIGNED DEFAULT NULL, ADD display_count SMALLINT UNSIGNED DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE quiz_attempt_answer ALTER display_count DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempt_answer DROP served_at, DROP elapsed_ms, DROP display_count');
    }
}
