<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Makes the progression's two duration columns say what they hold: MINUTES.
 *
 * Séance durations are authored in minutes in the library (SeanceTemplate/SeanceInstance::$duree -
 * "55" is a 55-minute séance), but the progression module read them as decimal hours, so a
 * 55-minute séance consumed 55 hours of a class's year and never fit any créneau. Both columns are
 * renamed with their unit in the name so the mix-up can't silently come back.
 *
 * The two conversions differ on purpose:
 * - progression_seance.planned_duration was copied verbatim from SeanceInstance::$duree, so it
 *   already held minutes and only needs rounding to the new INT column.
 * - progression_seance_placement.duration was written by the placement service from a créneau's
 *   LessonSession::$length, which really is decimal hours - hence the ×60.
 */
final class Version20260730150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression pédagogique: store séance/placement durations as explicit minutes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_seance ADD planned_minutes INT DEFAULT NULL');
        $this->addSql('UPDATE progression_seance SET planned_minutes = ROUND(planned_duration) WHERE planned_duration IS NOT NULL');
        $this->addSql('ALTER TABLE progression_seance DROP planned_duration');

        $this->addSql('ALTER TABLE progression_seance_placement ADD duration_minutes INT DEFAULT NULL');
        $this->addSql('UPDATE progression_seance_placement SET duration_minutes = ROUND(duration * 60) WHERE duration IS NOT NULL');
        $this->addSql('ALTER TABLE progression_seance_placement DROP duration');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_seance ADD planned_duration NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('UPDATE progression_seance SET planned_duration = planned_minutes WHERE planned_minutes IS NOT NULL');
        $this->addSql('ALTER TABLE progression_seance DROP planned_minutes');

        $this->addSql('ALTER TABLE progression_seance_placement ADD duration NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('UPDATE progression_seance_placement SET duration = duration_minutes / 60 WHERE duration_minutes IS NOT NULL');
        $this->addSql('ALTER TABLE progression_seance_placement DROP duration_minutes');
    }
}
