<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portfolio, lot 5: the engagement a réalisation was imported from, and the weekly watch of a
 * référentiel's RNCP fiche (app:rncp:check).
 */
final class Version20260929171733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Portfolio : engagement source d’une réalisation, veille RNCP du référentiel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_achievement ADD source_engagement_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE portfolio_achievement ADD CONSTRAINT FK_284A49251663BEF5 FOREIGN KEY (source_engagement_id) REFERENCES engagement_declaration (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_284A49251663BEF5 ON portfolio_achievement (source_engagement_id)');
        $this->addSql('ALTER TABLE referential ADD rncp_watch JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE portfolio_achievement DROP FOREIGN KEY FK_284A49251663BEF5');
        $this->addSql('DROP INDEX IDX_284A49251663BEF5 ON portfolio_achievement');
        $this->addSql('ALTER TABLE portfolio_achievement DROP source_engagement_id');
        $this->addSql('ALTER TABLE referential DROP rncp_watch');
    }
}
