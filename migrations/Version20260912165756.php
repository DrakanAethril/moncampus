<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260912165756 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accommodations: the catalogue, the accounts holding them, and the extra time frozen on a quiz attempt.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE accommodation (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, quiz_extra_time_percent NUMERIC(5, 2) DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_2D385412B03A8386 (created_by_id), INDEX IDX_2D385412F5A2E305 (inactivated_by_id), INDEX IDX_2D385412E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_accommodation (user_id INT NOT NULL, accommodation_id INT NOT NULL, INDEX IDX_C3B6F942A76ED395 (user_id), INDEX IDX_C3B6F9428F3692CD (accommodation_id), PRIMARY KEY (user_id, accommodation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE accommodation ADD CONSTRAINT FK_2D385412B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE accommodation ADD CONSTRAINT FK_2D385412F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE accommodation ADD CONSTRAINT FK_2D385412E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE user_accommodation ADD CONSTRAINT FK_C3B6F942A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_accommodation ADD CONSTRAINT FK_C3B6F9428F3692CD FOREIGN KEY (accommodation_id) REFERENCES accommodation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_attempt ADD extra_time_percent NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accommodation DROP FOREIGN KEY FK_2D385412B03A8386');
        $this->addSql('ALTER TABLE accommodation DROP FOREIGN KEY FK_2D385412F5A2E305');
        $this->addSql('ALTER TABLE accommodation DROP FOREIGN KEY FK_2D385412E562D849');
        $this->addSql('ALTER TABLE user_accommodation DROP FOREIGN KEY FK_C3B6F942A76ED395');
        $this->addSql('ALTER TABLE user_accommodation DROP FOREIGN KEY FK_C3B6F9428F3692CD');
        $this->addSql('DROP TABLE accommodation');
        $this->addSql('DROP TABLE user_accommodation');
        $this->addSql('ALTER TABLE quiz_attempt DROP extra_time_percent');
    }
}
