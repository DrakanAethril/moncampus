<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705174455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add option and modality entities, many-to-many linked to cohort';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modality (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, ldap_group_id INT UNSIGNED DEFAULT NULL, INDEX IDX_307988C0E1E736B9 (ldap_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE modality_cohort (modality_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_109C55C92D6D889B (modality_id), INDEX IDX_109C55C935983C93 (cohort_id), PRIMARY KEY (modality_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `option` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, short_name VARCHAR(255) NOT NULL, ldap_group_id INT UNSIGNED DEFAULT NULL, INDEX IDX_5A8600B0E1E736B9 (ldap_group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE option_cohort (option_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_90E8C3BCA7C41D6F (option_id), INDEX IDX_90E8C3BC35983C93 (cohort_id), PRIMARY KEY (option_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE modality ADD CONSTRAINT FK_307988C0E1E736B9 FOREIGN KEY (ldap_group_id) REFERENCES ldap_manage_group (id)');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT FK_109C55C92D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT FK_109C55C935983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `option` ADD CONSTRAINT FK_5A8600B0E1E736B9 FOREIGN KEY (ldap_group_id) REFERENCES ldap_manage_group (id)');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT FK_90E8C3BCA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT FK_90E8C3BC35983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE modality DROP FOREIGN KEY FK_307988C0E1E736B9');
        $this->addSql('ALTER TABLE modality_cohort DROP FOREIGN KEY FK_109C55C92D6D889B');
        $this->addSql('ALTER TABLE modality_cohort DROP FOREIGN KEY FK_109C55C935983C93');
        $this->addSql('ALTER TABLE `option` DROP FOREIGN KEY FK_5A8600B0E1E736B9');
        $this->addSql('ALTER TABLE option_cohort DROP FOREIGN KEY FK_90E8C3BCA7C41D6F');
        $this->addSql('ALTER TABLE option_cohort DROP FOREIGN KEY FK_90E8C3BC35983C93');
        $this->addSql('DROP TABLE modality');
        $this->addSql('DROP TABLE modality_cohort');
        $this->addSql('DROP TABLE `option`');
        $this->addSql('DROP TABLE option_cohort');
    }
}
