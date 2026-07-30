<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops progression.display_step. It stored the "pas de construction" (mois/semaine/séance) picked
 * on the creation screen, which the design itself called "un zoom, pas un engagement" - no screen
 * ever read it back, so it was removed rather than left as a field nothing acts on. Losing the
 * stored value costs nothing: it never influenced any behaviour.
 */
final class Version20260730093536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression pédagogique: drop the unused progression.display_step column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE progression DROP display_step');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE progression ADD display_step VARCHAR(20) NOT NULL');
    }
}
