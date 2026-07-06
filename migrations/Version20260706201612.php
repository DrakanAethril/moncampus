<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706201612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE lesson_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, agenda_color VARCHAR(20) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_B5AF914DB03A8386 (created_by_id), INDEX IDX_B5AF914DF5A2E305 (inactivated_by_id), INDEX IDX_B5AF914DE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_type ADD CONSTRAINT FK_B5AF914DB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_type ADD CONSTRAINT FK_B5AF914DF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE lesson_type ADD CONSTRAINT FK_B5AF914DE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE lesson_type DROP FOREIGN KEY FK_B5AF914DB03A8386');
        $this->addSql('ALTER TABLE lesson_type DROP FOREIGN KEY FK_B5AF914DF5A2E305');
        $this->addSql('ALTER TABLE lesson_type DROP FOREIGN KEY FK_B5AF914DE562D849');
        $this->addSql('DROP TABLE lesson_type');
    }
}
