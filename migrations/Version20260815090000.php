<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What a training referential fiche needs: the body of each competency on skill, a code and an order on skill/skill_group, the prepared certification per option, and the letterhead identifiers of the training centre';
    }

    public function up(Schema $schema): void
    {
        // Every new skill column is nullable: they are filled by hand or by
        // app:import-tsf-referential afterwards, and an existing referential has none of them.
        $this->addSql('ALTER TABLE skill ADD code VARCHAR(20) DEFAULT NULL, ADD occupation_description LONGTEXT DEFAULT NULL, ADD knowledge_html LONGTEXT DEFAULT NULL, ADD activities_html LONGTEXT DEFAULT NULL, ADD performance_criteria_html LONGTEXT DEFAULT NULL, ADD diagnostic_assessment_html LONGTEXT DEFAULT NULL, ADD summative_assessment_html LONGTEXT DEFAULT NULL, ADD certifying_assessment_html LONGTEXT DEFAULT NULL, ADD volume_hours NUMERIC(10, 2) DEFAULT NULL, ADD teaching_period_label VARCHAR(255) DEFAULT NULL, ADD teacher_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE skill ADD CONSTRAINT FK_5E3DE47741807E1D FOREIGN KEY (teacher_id) REFERENCES user (id)');
        $this->addSql('CREATE INDEX IDX_5E3DE47741807E1D ON skill (teacher_id)');

        // `order` is NOT NULL on a populated table, so it needs a DEFAULT for the duration of the
        // ALTER and no DEFAULT afterwards - the entity declares none, and a lingering one shows up
        // as schema drift on the next schema:validate.
        $this->addSql('ALTER TABLE skill ADD `order` INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE skill ALTER `order` DROP DEFAULT');
        $this->addSql('ALTER TABLE skill_group ADD code VARCHAR(20) DEFAULT NULL, ADD `order` INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE skill_group ALTER `order` DROP DEFAULT');

        // Seed the order from the existing row ids rather than leaving every row on 0: the
        // referential was created in reading order, so id order IS the right order today. Doing it
        // here means the screens can sort on `order` from the first request after the deploy.
        $this->addSql('UPDATE skill SET `order` = id');
        $this->addSql('UPDATE skill_group SET `order` = id');

        $this->addSql('CREATE TABLE program_certification (id INT AUTO_INCREMENT NOT NULL, program_id INT NOT NULL, option_id INT DEFAULT NULL, label VARCHAR(255) NOT NULL, kind VARCHAR(20) NOT NULL, rncp_code VARCHAR(20) DEFAULT NULL, level INT DEFAULT NULL, certifier VARCHAR(255) DEFAULT NULL, INDEX IDX_C9757DED3EB8070A (program_id), INDEX IDX_C9757DEDA7C41D6F (option_id), UNIQUE INDEX program_certification_unique (program_id, option_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE program_certification ADD CONSTRAINT FK_C9757DED3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_certification ADD CONSTRAINT FK_C9757DEDA7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id)');

        $this->addSql('ALTER TABLE internship_formation_center ADD siret VARCHAR(20) DEFAULT NULL, ADD ape_code VARCHAR(10) DEFAULT NULL, ADD activity_declaration_number VARCHAR(30) DEFAULT NULL, ADD activity_declaration_authority VARCHAR(255) DEFAULT NULL, ADD cfa_name VARCHAR(255) DEFAULT NULL, ADD cfa_address VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_formation_center DROP siret, DROP ape_code, DROP activity_declaration_number, DROP activity_declaration_authority, DROP cfa_name, DROP cfa_address');

        $this->addSql('ALTER TABLE program_certification DROP FOREIGN KEY FK_C9757DED3EB8070A');
        $this->addSql('ALTER TABLE program_certification DROP FOREIGN KEY FK_C9757DEDA7C41D6F');
        $this->addSql('DROP TABLE program_certification');

        $this->addSql('ALTER TABLE skill_group DROP code, DROP `order`');

        $this->addSql('ALTER TABLE skill DROP FOREIGN KEY FK_5E3DE47741807E1D');
        $this->addSql('DROP INDEX IDX_5E3DE47741807E1D ON skill');
        $this->addSql('ALTER TABLE skill DROP code, DROP occupation_description, DROP knowledge_html, DROP activities_html, DROP performance_criteria_html, DROP diagnostic_assessment_html, DROP summative_assessment_html, DROP certifying_assessment_html, DROP volume_hours, DROP teaching_period_label, DROP teacher_id, DROP `order`');
    }
}
