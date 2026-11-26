<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM: « Remonter sur l'accueil » on a piece - unticked for every piece already there.
 */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CRM: home alert flag on pieces';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_piece ADD home_alert TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_piece DROP home_alert');
    }
}
