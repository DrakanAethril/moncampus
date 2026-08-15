<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Opt-in flag for the TSF referential export tab on a program';
    }

    public function up(Schema $schema): void
    {
        // Keeps its DEFAULT, unlike the `order` columns of the previous migration: the entity
        // declares options: ['default' => false], so the schema is expected to carry it.
        $this->addSql('ALTER TABLE program ADD tsf_export_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE program DROP tsf_export_enabled');
    }
}
