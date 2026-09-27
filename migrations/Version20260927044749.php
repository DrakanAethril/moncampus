<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927044749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an equipment type or piece be stored in one of the platform rooms';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipment_item ADD storage_room_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment_item ADD CONSTRAINT FK_259FF418232B43F1 FOREIGN KEY (storage_room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_259FF418232B43F1 ON equipment_item (storage_room_id)');
        $this->addSql('ALTER TABLE equipment_type ADD storage_room_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment_type ADD CONSTRAINT FK_B65A862F232B43F1 FOREIGN KEY (storage_room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B65A862F232B43F1 ON equipment_type (storage_room_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipment_item DROP FOREIGN KEY FK_259FF418232B43F1');
        $this->addSql('DROP INDEX IDX_259FF418232B43F1 ON equipment_item');
        $this->addSql('ALTER TABLE equipment_item DROP storage_room_id');
        $this->addSql('ALTER TABLE equipment_type DROP FOREIGN KEY FK_B65A862F232B43F1');
        $this->addSql('DROP INDEX IDX_B65A862F232B43F1 ON equipment_type');
        $this->addSql('ALTER TABLE equipment_type DROP storage_room_id');
    }
}
