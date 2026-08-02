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
     * Suite de Version20260802120000, qui ne couvrait qu'un des deux chemins par lesquels un
     * créneau a pu prendre le nom d'une séance :
     *  - les placements d'une progression (traités là-bas) ;
     *  - l'ancien écran /programs/{id}/sequences/seances/{id}/schedule, supprimé le 30/07/2026,
     *    qui créait le créneau depuis la séance et lui donnait son titre (commit 6b01adc). Ces
     *    créneaux-là sont reliés par SeanceInstance::$lessonSession, pas par un placement, et la
     *    première migration passait donc à côté.
     *
     * Même prudence : seul un titre encore identique à celui de la séance est effacé, un créneau
     * renommé à la main depuis dit autre chose. L'ancien écran créait un créneau par séance, sans
     * suffixe « (1/2) » - d'où l'égalité stricte, là où l'autre migration devait aussi couvrir la
     * forme suffixée.
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
        // Sans retour possible, pour la même raison que Version20260802120000 : le titre effacé
        // était une copie de celui de la séance, qui elle n'a rien perdu.
        $this->throwIrreversibleMigrationException();
    }
}
