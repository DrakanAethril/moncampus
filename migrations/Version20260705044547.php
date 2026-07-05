<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705044547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ldap_manage_group and ldap_manage_user tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS ldap_manage_group (id INT UNSIGNED AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, added_by VARCHAR(255) DEFAULT \'direct\' NOT NULL, state SMALLINT UNSIGNED DEFAULT 0 NOT NULL, pid INT UNSIGNED DEFAULT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, log LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_ldap_manage_group_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB AUTO_INCREMENT = 99100');
        $this->addSql('CREATE TABLE IF NOT EXISTS ldap_manage_user (id INT UNSIGNED AUTO_INCREMENT NOT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, user_type VARCHAR(255) NOT NULL, user_groups VARCHAR(255) DEFAULT \'\' NOT NULL, action_type VARCHAR(255) NOT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, added_by VARCHAR(255) DEFAULT \'direct\' NOT NULL, login VARCHAR(255) DEFAULT NULL, password VARBINARY(255) DEFAULT NULL, state SMALLINT UNSIGNED DEFAULT 0 NOT NULL, pid INT UNSIGNED DEFAULT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, log LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB AUTO_INCREMENT = 11000');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ldap_manage_group');
        $this->addSql('DROP TABLE IF EXISTS ldap_manage_user');
    }
}
