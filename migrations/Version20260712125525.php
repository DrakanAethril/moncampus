<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712125525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the Bloc entity - fully unused since the teaching-sequence library moved to free-text tags';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY `FK_C778955AB03A8386`');
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY `FK_C778955AE562D849`');
        $this->addSql('ALTER TABLE bloc DROP FOREIGN KEY `FK_C778955AF5A2E305`');
        $this->addSql('DROP TABLE bloc');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bloc (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, label VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_C778955AB03A8386 (created_by_id), INDEX IDX_C778955AE562D849 (last_updated_by_id), INDEX IDX_C778955AF5A2E305 (inactivated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT `FK_C778955AB03A8386` FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT `FK_C778955AE562D849` FOREIGN KEY (last_updated_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE bloc ADD CONSTRAINT `FK_C778955AF5A2E305` FOREIGN KEY (inactivated_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
