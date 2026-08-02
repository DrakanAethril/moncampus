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
     * Valider une séquence écrivait le titre de la séance sur le créneau, si bien que l'emploi du
     * temps annonçait une séance là où il doit annoncer une matière. App\Service\
     * ProgressionPlacementService::validate() ne l'écrit plus ; restent les créneaux déjà nommés,
     * que cette migration rend à leur matière - LessonSession::getDisplayName() retombe alors
     * dessus toute seule, partout où le créneau s'affiche.
     *
     * Le titre n'est effacé que s'il est bien celui qu'écrivait validate() - le titre de la séance,
     * suffixé de « (1/2) » quand elle occupait plusieurs créneaux. Un créneau renommé à la main
     * depuis dit autre chose et n'est pas touché, même règle que releaseSequence().
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
        // Sans retour possible : le titre effacé était une copie de celui de la séance, et rien ne
        // distingue plus un créneau que cette migration a nettoyé d'un créneau qui n'a jamais porté
        // de titre. La séance, elle, n'a rien perdu - c'est son propre titre qui fait foi.
        $this->throwIrreversibleMigrationException();
    }
}
