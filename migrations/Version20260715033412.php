<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260715033412 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ldap_manage_password (id INT UNSIGNED AUTO_INCREMENT NOT NULL, login VARCHAR(255) NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, added_by VARCHAR(255) DEFAULT \'direct\' NOT NULL, password VARBINARY(255) DEFAULT NULL, state SMALLINT UNSIGNED DEFAULT 0 NOT NULL, pid INT UNSIGNED DEFAULT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, log LONGTEXT DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_B72A94A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ldap_manage_password ADD CONSTRAINT FK_B72A94A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ldap_manage_password DROP FOREIGN KEY FK_B72A94A76ED395');
        $this->addSql('DROP TABLE ldap_manage_password');
    }
}
