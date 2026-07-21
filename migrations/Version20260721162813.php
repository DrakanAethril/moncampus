<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721162813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE evaluation_period (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, start_date DATETIME NOT NULL, end_date DATETIME NOT NULL, evaluation_period_group_id INT NOT NULL, INDEX IDX_FC72B39EF6877E (evaluation_period_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE evaluation_period_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_925A71ABB03A8386 (created_by_id), INDEX IDX_925A71ABF5A2E305 (inactivated_by_id), INDEX IDX_925A71ABE562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE evaluation_period ADD CONSTRAINT FK_FC72B39EF6877E FOREIGN KEY (evaluation_period_group_id) REFERENCES evaluation_period_group (id)');
        $this->addSql('ALTER TABLE evaluation_period_group ADD CONSTRAINT FK_925A71ABB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE evaluation_period_group ADD CONSTRAINT FK_925A71ABF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE evaluation_period_group ADD CONSTRAINT FK_925A71ABE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE program ADD evaluation_period_group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784EF6877E FOREIGN KEY (evaluation_period_group_id) REFERENCES evaluation_period_group (id)');
        $this->addSql('CREATE INDEX IDX_92ED7784EF6877E ON program (evaluation_period_group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation_period DROP FOREIGN KEY FK_FC72B39EF6877E');
        $this->addSql('ALTER TABLE evaluation_period_group DROP FOREIGN KEY FK_925A71ABB03A8386');
        $this->addSql('ALTER TABLE evaluation_period_group DROP FOREIGN KEY FK_925A71ABF5A2E305');
        $this->addSql('ALTER TABLE evaluation_period_group DROP FOREIGN KEY FK_925A71ABE562D849');
        $this->addSql('DROP TABLE evaluation_period');
        $this->addSql('DROP TABLE evaluation_period_group');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784EF6877E');
        $this->addSql('DROP INDEX IDX_92ED7784EF6877E ON program');
        $this->addSql('ALTER TABLE program DROP evaluation_period_group_id');
    }
}
