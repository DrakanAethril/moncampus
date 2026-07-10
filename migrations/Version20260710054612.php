<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710054612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Modality.color, same purpose/pattern as the recent Option.color.';
    }

    public function up(Schema $schema): void
    {
        // Starts nullable so existing rows can be backfilled with a default before the column is
        // tightened to NOT NULL below - same reasoning as Option.color's migration, modality
        // already has real rows in both dev and prod.
        $this->addSql('ALTER TABLE modality ADD color VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE modality SET color = '#206bc4' WHERE color IS NULL");
        $this->addSql('ALTER TABLE modality MODIFY color VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modality DROP color');
    }
}
