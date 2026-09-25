<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Équipe pédagogique » of the Livret de l'alternant, written line by line in UFA > Formations >
 * « Équipe »: one JSON column, since the lines are free text related to nothing (see
 * App\Service\TeachingTeam). Null until a formation writes its first line.
 */
final class Version20260925075209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livret : équipe pédagogique saisie en texte libre par formation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info ADD teaching_team JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_program_info DROP teaching_team');
    }
}
