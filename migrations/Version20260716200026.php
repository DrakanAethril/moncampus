<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716200026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GroupType (optional display grouping for Group) and Group.groupType relation';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE group_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_A5840C59B03A8386 (created_by_id), INDEX IDX_A5840C59F5A2E305 (inactivated_by_id), INDEX IDX_A5840C59E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_type ADD CONSTRAINT FK_A5840C59B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE group_type ADD CONSTRAINT FK_A5840C59F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE group_type ADD CONSTRAINT FK_A5840C59E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE `group` ADD group_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_6DC044C5434CD89F FOREIGN KEY (group_type_id) REFERENCES group_type (id)');
        $this->addSql('CREATE INDEX IDX_6DC044C5434CD89F ON `group` (group_type_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE group_type DROP FOREIGN KEY FK_A5840C59B03A8386');
        $this->addSql('ALTER TABLE group_type DROP FOREIGN KEY FK_A5840C59F5A2E305');
        $this->addSql('ALTER TABLE group_type DROP FOREIGN KEY FK_A5840C59E562D849');
        $this->addSql('DROP TABLE group_type');
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_6DC044C5434CD89F');
        $this->addSql('DROP INDEX IDX_6DC044C5434CD89F ON `group`');
        $this->addSql('ALTER TABLE `group` DROP group_type_id');
    }
}
