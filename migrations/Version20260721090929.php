<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721090929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE group_batch (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, `groups` JSON NOT NULL, created_at DATETIME NOT NULL, program_id INT NOT NULL, teacher_id INT NOT NULL, INDEX IDX_A82779B33EB8070A (program_id), INDEX IDX_A82779B341807E1D (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_batch ADD CONSTRAINT FK_A82779B33EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE group_batch ADD CONSTRAINT FK_A82779B341807E1D FOREIGN KEY (teacher_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE group_batch DROP FOREIGN KEY FK_A82779B33EB8070A');
        $this->addSql('ALTER TABLE group_batch DROP FOREIGN KEY FK_A82779B341807E1D');
        $this->addSql('DROP TABLE group_batch');
    }
}
