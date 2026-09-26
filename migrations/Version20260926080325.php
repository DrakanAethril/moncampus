<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion > Matériel inventory counts: the count itself, and what it found for each type - pieces
 * ticked, or quantities counted in the reserve and in the rooms.
 */
final class Version20260926080325 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the equipment inventory count tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE equipment_stocktake (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, includes_in_use TINYINT DEFAULT 0 NOT NULL, abandoned TINYINT DEFAULT 0 NOT NULL, shortage_count INT DEFAULT 0 NOT NULL, surplus_count INT DEFAULT 0 NOT NULL, category_id INT DEFAULT NULL, started_by_id INT DEFAULT NULL, closed_by_id INT DEFAULT NULL, INDEX IDX_DEE5846E12469DE2 (category_id), INDEX IDX_DEE5846E9740C9D5 (started_by_id), INDEX IDX_DEE5846EE1FA7797 (closed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE equipment_stocktake_line (id INT AUTO_INCREMENT NOT NULL, counted_available INT DEFAULT NULL, counted_in_use INT DEFAULT NULL, recorded_at DATETIME NOT NULL, stocktake_id INT NOT NULL, type_id INT NOT NULL, item_id INT DEFAULT NULL, recorded_by_id INT DEFAULT NULL, INDEX IDX_789AD677EEC9FE3 (stocktake_id), INDEX IDX_789AD677C54C8C93 (type_id), INDEX IDX_789AD677126F525E (item_id), INDEX IDX_789AD677D05A957B (recorded_by_id), UNIQUE INDEX uniq_equipment_stocktake_item (stocktake_id, item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE equipment_stocktake ADD CONSTRAINT FK_DEE5846E12469DE2 FOREIGN KEY (category_id) REFERENCES equipment_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_stocktake ADD CONSTRAINT FK_DEE5846E9740C9D5 FOREIGN KEY (started_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_stocktake ADD CONSTRAINT FK_DEE5846EE1FA7797 FOREIGN KEY (closed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE equipment_stocktake_line ADD CONSTRAINT FK_789AD677EEC9FE3 FOREIGN KEY (stocktake_id) REFERENCES equipment_stocktake (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_stocktake_line ADD CONSTRAINT FK_789AD677C54C8C93 FOREIGN KEY (type_id) REFERENCES equipment_type (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_stocktake_line ADD CONSTRAINT FK_789AD677126F525E FOREIGN KEY (item_id) REFERENCES equipment_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE equipment_stocktake_line ADD CONSTRAINT FK_789AD677D05A957B FOREIGN KEY (recorded_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE equipment_stocktake DROP FOREIGN KEY FK_DEE5846E12469DE2');
        $this->addSql('ALTER TABLE equipment_stocktake DROP FOREIGN KEY FK_DEE5846E9740C9D5');
        $this->addSql('ALTER TABLE equipment_stocktake DROP FOREIGN KEY FK_DEE5846EE1FA7797');
        $this->addSql('ALTER TABLE equipment_stocktake_line DROP FOREIGN KEY FK_789AD677EEC9FE3');
        $this->addSql('ALTER TABLE equipment_stocktake_line DROP FOREIGN KEY FK_789AD677C54C8C93');
        $this->addSql('ALTER TABLE equipment_stocktake_line DROP FOREIGN KEY FK_789AD677126F525E');
        $this->addSql('ALTER TABLE equipment_stocktake_line DROP FOREIGN KEY FK_789AD677D05A957B');
        $this->addSql('DROP TABLE equipment_stocktake');
        $this->addSql('DROP TABLE equipment_stocktake_line');
    }
}
