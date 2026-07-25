<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725163231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE topic CHANGE target_cm_hours target_cm_hours NUMERIC(10, 2) NOT NULL, CHANGE target_td_hours target_td_hours NUMERIC(10, 2) NOT NULL, CHANGE target_tp_hours target_tp_hours NUMERIC(10, 2) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE topic CHANGE target_cm_hours target_cm_hours INT NOT NULL, CHANGE target_td_hours target_td_hours INT NOT NULL, CHANGE target_tp_hours target_tp_hours INT NOT NULL');
    }
}
