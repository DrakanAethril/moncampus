<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Rend leur matière aux créneaux nommés par l'ancien écran « planifier une séance ».";
    }

    /**
     * Follow-up to Version20260802120000, which only covered one of the two paths by which a slot
     * could have taken a séance's name:
     *  - the placements of a progression (handled there);
     *  - the old /programs/{id}/sequences/seances/{id}/schedule screen, removed on 30/07/2026, which
     *    created the slot from the séance and gave it its title (commit 6b01adc). Those slots are
     *    linked by SeanceInstance::$lessonSession, not by a placement, and the first migration
     *    therefore missed them.
     *
     * Same caution: only a title still identical to the séance's is erased, a slot renamed by hand
     * since then says something else. The old screen created one slot per séance, with no « (1/2) »
     * suffix - hence the strict equality, where the other migration also had to cover the suffixed
     * form.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE lesson_session ls
            INNER JOIN seance_instance si ON si.lesson_session_id = ls.id
            SET ls.title = NULL
            WHERE ls.title IS NOT NULL
              AND ls.title = si.titre
            SQL);
    }

    public function down(Schema $schema): void
    {
        // With no way back, for the same reason as Version20260802120000: the title erased was a copy
        // of the séance's, which itself lost nothing.
        $this->throwIrreversibleMigrationException();
    }
}
