<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion > Matériel: the small-equipment inventory - categories, storage places, types with their
 * stored counters, unit-tracked pieces, the append-only journal, and the one-row sequence the label
 * numbers are drawn from.
 */
final class Version20260926070317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the small-equipment inventory tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE equipment_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, creation_date DATETIME NOT NULL, UNIQUE INDEX uniq_equipment_category_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_code_sequence (id INT NOT NULL, next_number INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_item (id INT AUTO_INCREMENT NOT NULL, code_number INT NOT NULL, status VARCHAR(20) NOT NULL, serial_number VARCHAR(120) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, labeled_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, type_id INT NOT NULL, room_id INT DEFAULT NULL, location_id INT DEFAULT NULL, INDEX IDX_259FF418C54C8C93 (type_id), INDEX IDX_259FF41854177093 (room_id), INDEX IDX_259FF41864D218E (location_id), INDEX idx_equipment_item_labeled (labeled_at), UNIQUE INDEX uniq_equipment_item_code_number (code_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_location (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, creation_date DATETIME NOT NULL, UNIQUE INDEX uniq_equipment_location_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_movement (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, quantity INT NOT NULL, occurred_at DATETIME NOT NULL, recorded_at DATETIME NOT NULL, note LONGTEXT DEFAULT NULL, type_id INT NOT NULL, item_id INT DEFAULT NULL, recorded_by_id INT DEFAULT NULL, room_id INT DEFAULT NULL, INDEX IDX_C41E11D1C54C8C93 (type_id), INDEX IDX_C41E11D1126F525E (item_id), INDEX IDX_C41E11D1D05A957B (recorded_by_id), INDEX IDX_C41E11D154177093 (room_id), INDEX idx_equipment_movement_type_kind (type_id, kind), INDEX idx_equipment_movement_occurred (occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(160) NOT NULL, unit_tracked TINYINT NOT NULL, brand VARCHAR(120) DEFAULT NULL, model VARCHAR(120) DEFAULT NULL, unit_price NUMERIC(10, 2) DEFAULT NULL, alert_threshold INT DEFAULT NULL, target_stock INT DEFAULT NULL, supplier_reference VARCHAR(500) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, available_count INT DEFAULT 0 NOT NULL, in_use_count INT DEFAULT 0 NOT NULL, on_order_count INT DEFAULT 0 NOT NULL, creation_date DATETIME NOT NULL, category_id INT DEFAULT NULL, location_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_B65A862F12469DE2 (category_id), INDEX IDX_B65A862F64D218E (location_id), INDEX IDX_B65A862FB03A8386 (created_by_id), UNIQUE INDEX uniq_equipment_type_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE equipment_item ADD CONSTRAINT FK_259FF418C54C8C93 FOREIGN KEY (type_id) REFERENCES equipment_type (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_item ADD CONSTRAINT FK_259FF41854177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_item ADD CONSTRAINT FK_259FF41864D218E FOREIGN KEY (location_id) REFERENCES equipment_location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_movement ADD CONSTRAINT FK_C41E11D1C54C8C93 FOREIGN KEY (type_id) REFERENCES equipment_type (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_movement ADD CONSTRAINT FK_C41E11D1126F525E FOREIGN KEY (item_id) REFERENCES equipment_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_movement ADD CONSTRAINT FK_C41E11D1D05A957B FOREIGN KEY (recorded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_movement ADD CONSTRAINT FK_C41E11D154177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_type ADD CONSTRAINT FK_B65A862F12469DE2 FOREIGN KEY (category_id) REFERENCES equipment_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_type ADD CONSTRAINT FK_B65A862F64D218E FOREIGN KEY (location_id) REFERENCES equipment_location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_type ADD CONSTRAINT FK_B65A862FB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE equipment_item DROP FOREIGN KEY FK_259FF418C54C8C93');
        $this->addSql('ALTER TABLE equipment_item DROP FOREIGN KEY FK_259FF41854177093');
        $this->addSql('ALTER TABLE equipment_item DROP FOREIGN KEY FK_259FF41864D218E');
        $this->addSql('ALTER TABLE equipment_movement DROP FOREIGN KEY FK_C41E11D1C54C8C93');
        $this->addSql('ALTER TABLE equipment_movement DROP FOREIGN KEY FK_C41E11D1126F525E');
        $this->addSql('ALTER TABLE equipment_movement DROP FOREIGN KEY FK_C41E11D1D05A957B');
        $this->addSql('ALTER TABLE equipment_movement DROP FOREIGN KEY FK_C41E11D154177093');
        $this->addSql('ALTER TABLE equipment_type DROP FOREIGN KEY FK_B65A862F12469DE2');
        $this->addSql('ALTER TABLE equipment_type DROP FOREIGN KEY FK_B65A862F64D218E');
        $this->addSql('ALTER TABLE equipment_type DROP FOREIGN KEY FK_B65A862FB03A8386');
        $this->addSql('DROP TABLE equipment_category');
        $this->addSql('DROP TABLE equipment_code_sequence');
        $this->addSql('DROP TABLE equipment_item');
        $this->addSql('DROP TABLE equipment_location');
        $this->addSql('DROP TABLE equipment_movement');
        $this->addSql('DROP TABLE equipment_type');
    }
}
