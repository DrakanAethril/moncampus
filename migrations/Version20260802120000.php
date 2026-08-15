<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rend leur matière aux créneaux qu'une séance de progression avait renommés.";
    }

    /**
     * Validating a séquence used to write the séance's title onto the slot, so the timetable
     * announced a séance where it must announce a matière. App\Service\
     * ProgressionPlacementService::validate() no longer writes it; what remain are the slots already
     * named, which this migration gives back to their matière - LessonSession::getDisplayName() then
     * falls back on it by itself, everywhere the slot is displayed.
     *
     * The title is only erased if it really is the one validate() used to write - the séance's title,
     * suffixed with « (1/2) » when it took several slots. A slot renamed by hand since then says
     * something else and is not touched, same rule as releaseSequence().
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE lesson_session ls
            INNER JOIN progression_seance_placement p ON p.lesson_session_id = ls.id
            INNER JOIN progression_seance s ON s.id = p.progression_seance_id
            SET ls.title = NULL
            WHERE ls.title IS NOT NULL
              AND (ls.title = s.title OR ls.title LIKE CONCAT(s.title, ' (%/%)'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // With no way back: the title erased was a copy of the séance's, and nothing tells a slot
        // this migration cleaned from a slot that never carried a title. The séance itself lost
        // nothing - its own title is what counts.
        $this->throwIrreversibleMigrationException();
    }
}
