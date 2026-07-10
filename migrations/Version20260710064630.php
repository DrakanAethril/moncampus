<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710064630 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ldap_computer/ldap_service, read-only LDAP mirrors for Directory > Computers/Services (populated only by their own manual sync action).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ldap_computer (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              dns_host_name VARCHAR(255) DEFAULT NULL,
              operating_system VARCHAR(255) DEFAULT NULL,
              creation_date DATETIME NOT NULL,
              created_by_id INT NOT NULL,
              INDEX IDX_BD2B4858B03A8386 (created_by_id),
              UNIQUE INDEX ldap_computer_name_unique (name),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ldap_service (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              creation_date DATETIME NOT NULL,
              created_by_id INT NOT NULL,
              INDEX IDX_88F10A01B03A8386 (created_by_id),
              UNIQUE INDEX ldap_service_name_unique (name),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ldap_computer
            ADD
              CONSTRAINT FK_BD2B4858B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ldap_service
            ADD
              CONSTRAINT FK_88F10A01B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ldap_computer DROP FOREIGN KEY FK_BD2B4858B03A8386');
        $this->addSql('ALTER TABLE ldap_service DROP FOREIGN KEY FK_88F10A01B03A8386');
        $this->addSql('DROP TABLE ldap_computer');
        $this->addSql('DROP TABLE ldap_service');
    }
}
