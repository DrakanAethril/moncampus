<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709172220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Option.color (a hex string, same purpose as LessonType.agendaColor) for badges like the ones on the Program students/teachers lists.';
    }

    public function up(Schema $schema): void
    {
        // Starts nullable so existing rows can be backfilled with a default before the column is
        // tightened to NOT NULL below - unlike LessonType (a brand new table when its own color
        // column was added), Option already has real rows in both dev and prod.
        $this->addSql('ALTER TABLE `option` ADD color VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE `option` SET color = '#206bc4' WHERE color IS NULL");
        $this->addSql('ALTER TABLE `option` MODIFY color VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `option` DROP color');
    }
}
