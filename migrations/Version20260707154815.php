<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707154815 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // DEFAULT 1 so existing Programs stay fully enabled (matches the entity's own
        // `= true` property defaults) instead of silently losing every feature on migrate.
        $this->addSql('ALTER TABLE program ADD timetable_management_enabled TINYINT NOT NULL DEFAULT 1, ADD financial_management_enabled TINYINT NOT NULL DEFAULT 1, ADD topic_skill_management_enabled TINYINT NOT NULL DEFAULT 1, ADD internship_management_enabled TINYINT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program DROP timetable_management_enabled, DROP financial_management_enabled, DROP topic_skill_management_enabled, DROP internship_management_enabled');
    }
}
