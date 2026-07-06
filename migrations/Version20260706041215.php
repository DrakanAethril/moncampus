<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706041215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add school_year and program entities; option/modality now relate to program instead of cohort (join tables option_program/modality_program)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE modality_program (modality_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_AAF691D42D6D889B (modality_id), INDEX IDX_AAF691D43EB8070A (program_id), PRIMARY KEY (modality_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE option_program (option_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_8A1960F1A7C41D6F (option_id), INDEX IDX_8A1960F13EB8070A (program_id), PRIMARY KEY (option_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, short_name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, cohort_id INT NOT NULL, school_year_id INT NOT NULL, INDEX IDX_92ED778435983C93 (cohort_id), INDEX IDX_92ED7784D2EECC3F (school_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE school_year (id INT AUTO_INCREMENT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE modality_program ADD CONSTRAINT FK_AAF691D42D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_program ADD CONSTRAINT FK_AAF691D43EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_program ADD CONSTRAINT FK_8A1960F1A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_program ADD CONSTRAINT FK_8A1960F13EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
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
        $this->addSql('CREATE TABLE modality_cohort (modality_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_109C55C92D6D889B (modality_id), INDEX IDX_109C55C935983C93 (cohort_id), PRIMARY KEY (modality_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE option_cohort (option_id INT NOT NULL, cohort_id INT NOT NULL, INDEX IDX_90E8C3BCA7C41D6F (option_id), INDEX IDX_90E8C3BC35983C93 (cohort_id), PRIMARY KEY (option_id, cohort_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT `FK_109C55C92D6D889B` FOREIGN KEY (modality_id) REFERENCES modality (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_cohort ADD CONSTRAINT `FK_109C55C935983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT `FK_90E8C3BC35983C93` FOREIGN KEY (cohort_id) REFERENCES cohort (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE option_cohort ADD CONSTRAINT `FK_90E8C3BCA7C41D6F` FOREIGN KEY (option_id) REFERENCES `option` (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE modality_program DROP FOREIGN KEY FK_AAF691D42D6D889B');
        $this->addSql('ALTER TABLE modality_program DROP FOREIGN KEY FK_AAF691D43EB8070A');
        $this->addSql('ALTER TABLE option_program DROP FOREIGN KEY FK_8A1960F1A7C41D6F');
        $this->addSql('ALTER TABLE option_program DROP FOREIGN KEY FK_8A1960F13EB8070A');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED778435983C93');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784D2EECC3F');
        $this->addSql('DROP TABLE modality_program');
        $this->addSql('DROP TABLE option_program');
        $this->addSql('DROP TABLE program');
        $this->addSql('DROP TABLE school_year');
    }
}
