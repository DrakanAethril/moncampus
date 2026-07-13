<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713060131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a color swatch field to PeriodType, same shape as Option/Modality.';
    }

    public function up(Schema $schema): void
    {
        // Nullable-add then backfill then NOT NULL, same pattern as Option/Modality's own color
        // retrofit (Version20260709172220/Version20260710054612) - safe regardless of whether
        // period_type already has rows.
        $this->addSql('ALTER TABLE period_type ADD color VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE period_type SET color = '#206bc4' WHERE color IS NULL");
        $this->addSql('ALTER TABLE period_type MODIFY color VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE period_type DROP color');
    }
}
