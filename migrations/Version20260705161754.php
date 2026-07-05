<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705161754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the school structure hierarchy: section, track, cohort';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cohort (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, ldap_group_id INT UNSIGNED DEFAULT NULL, track_id INT NOT NULL, INDEX IDX_D3B8C16BE1E736B9 (ldap_group_id), INDEX IDX_D3B8C16B5ED23C43 (track_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE section (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, ldap_group_id INT UNSIGNED DEFAULT NULL, INDEX IDX_2D737AEFE1E736B9 (ldap_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE track (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, ldap_group_id INT UNSIGNED DEFAULT NULL, section_id INT NOT NULL, INDEX IDX_D6E3F8A6E1E736B9 (ldap_group_id), INDEX IDX_D6E3F8A6D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cohort ADD CONSTRAINT FK_D3B8C16BE1E736B9 FOREIGN KEY (ldap_group_id) REFERENCES ldap_manage_group (id)');
        $this->addSql('ALTER TABLE cohort ADD CONSTRAINT FK_D3B8C16B5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id)');
        $this->addSql('ALTER TABLE section ADD CONSTRAINT FK_2D737AEFE1E736B9 FOREIGN KEY (ldap_group_id) REFERENCES ldap_manage_group (id)');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6E1E736B9 FOREIGN KEY (ldap_group_id) REFERENCES ldap_manage_group (id)');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT FK_D6E3F8A6D823E37A FOREIGN KEY (section_id) REFERENCES section (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cohort DROP FOREIGN KEY FK_D3B8C16BE1E736B9');
        $this->addSql('ALTER TABLE cohort DROP FOREIGN KEY FK_D3B8C16B5ED23C43');
        $this->addSql('ALTER TABLE section DROP FOREIGN KEY FK_2D737AEFE1E736B9');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6E1E736B9');
        $this->addSql('ALTER TABLE track DROP FOREIGN KEY FK_D6E3F8A6D823E37A');
        $this->addSql('DROP TABLE cohort');
        $this->addSql('DROP TABLE section');
        $this->addSql('DROP TABLE track');
    }
}
