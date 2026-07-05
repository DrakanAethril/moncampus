<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705184149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add school_year and program (formation) entities; option/modality now relate to program instead of cohort';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modality_formation (modality_id INT NOT NULL, formation_id INT NOT NULL, INDEX IDX_B39203BD2D6D889B (modality_id), INDEX IDX_B39203BD5200282E (formation_id), PRIMARY KEY (modality_id, formation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE option_formation (option_id INT NOT NULL, formation_id INT NOT NULL, INDEX IDX_91DB313FA7C41D6F (option_id), INDEX IDX_91DB313F5200282E (formation_id), PRIMARY KEY (option_id, formation_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, cohort_id INT NOT NULL, school_year_id INT NOT NULL, INDEX IDX_92ED778435983C93 (cohort_id), INDEX IDX_92ED7784D2EECC3F (school_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_year (id INT AUTO_INCREMENT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE modality_formation ADD CONSTRAINT FK_B39203BD2D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_formation ADD CONSTRAINT FK_B39203BD5200282E FOREIGN KEY (formation_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_formation ADD CONSTRAINT FK_91DB313FA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_formation ADD CONSTRAINT FK_91DB313F5200282E FOREIGN KEY (formation_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED778435983C93 FOREIGN KEY (cohort_id) REFERENCES cohort (id)');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE modality_cohort DROP FOREIGN KEY `FK_109C55C92D6D889B`');
        $this->addSql('ALTER TABLE modality_cohort DROP FOREIGN KEY `FK_109C55C935983C93`');
        $this->addSql('ALTER TABLE option_cohort DROP FOREIGN KEY `FK_90E8C3BC35983C93`');
        $this->addSql('ALTER TABLE option_cohort DROP FOREIGN KEY `FK_90E8C3BCA7C41D6F`');
        $this->addSql('DROP TABLE modality_cohort');
        $this->addSql('DROP TABLE option_cohort');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modality_cohort (modality_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_109C55C935983C93 (cohort_id), INDEX IDX_109C55C92D6D889B (modality_id), PRIMARY KEY (modality_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE option_cohort (option_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_90E8C3BCA7C41D6F (option_id), INDEX IDX_90E8C3BC35983C93 (cohort_id), PRIMARY KEY (option_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT `FK_109C55C92D6D889B` FOREIGN KEY (modality_id) REFERENCES modality (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT `FK_109C55C935983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT `FK_90E8C3BC35983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT `FK_90E8C3BCA7C41D6F` FOREIGN KEY (option_id) REFERENCES `option` (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_formation DROP FOREIGN KEY FK_B39203BD2D6D889B');
        $this->addSql('ALTER TABLE modality_formation DROP FOREIGN KEY FK_B39203BD5200282E');
        $this->addSql('ALTER TABLE option_formation DROP FOREIGN KEY FK_91DB313FA7C41D6F');
        $this->addSql('ALTER TABLE option_formation DROP FOREIGN KEY FK_91DB313F5200282E');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED778435983C93');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784D2EECC3F');
        $this->addSql('DROP TABLE modality_formation');
        $this->addSql('DROP TABLE option_formation');
        $this->addSql('DROP TABLE program');
        $this->addSql('DROP TABLE school_year');
    }
}
