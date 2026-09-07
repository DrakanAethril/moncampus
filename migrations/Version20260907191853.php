<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Skill::$descriptionHtml - the explanation shown under a competency's label in the Livret
 * Alternant and on the evaluation screens.
 */
final class Version20260907191853 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute la description d'une compétence du livret de l'alternant.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill ADD description_html LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill DROP description_html');
    }
}
