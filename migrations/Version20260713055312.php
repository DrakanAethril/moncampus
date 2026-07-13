<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260713055312 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add PeriodType and PeriodGroup entities, link Period to a PeriodGroup/PeriodType and Program to a PeriodGroup.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE period_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, school_year_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_5C68CCA0D2EECC3F (school_year_id), INDEX IDX_5C68CCA0B03A8386 (created_by_id), INDEX IDX_5C68CCA0F5A2E305 (inactivated_by_id), INDEX IDX_5C68CCA0E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE period_type (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_4AD8DD87B03A8386 (created_by_id), INDEX IDX_4AD8DD87F5A2E305 (inactivated_by_id), INDEX IDX_4AD8DD87E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE period_group ADD CONSTRAINT FK_5C68CCA0D2EECC3F FOREIGN KEY (school_year_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE period_group ADD CONSTRAINT FK_5C68CCA0B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period_group ADD CONSTRAINT FK_5C68CCA0F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period_group ADD CONSTRAINT FK_5C68CCA0E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period_type ADD CONSTRAINT FK_4AD8DD87B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period_type ADD CONSTRAINT FK_4AD8DD87F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period_type ADD CONSTRAINT FK_4AD8DD87E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE period ADD period_type_id INT NOT NULL, ADD period_group_id INT NOT NULL');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECE3EA529CB FOREIGN KEY (period_type_id) REFERENCES period_type (id)');
        $this->addSql('ALTER TABLE period ADD CONSTRAINT FK_C5B81ECE9B1FE924 FOREIGN KEY (period_group_id) REFERENCES period_group (id)');
        $this->addSql('CREATE INDEX IDX_C5B81ECE3EA529CB ON period (period_type_id)');
        $this->addSql('CREATE INDEX IDX_C5B81ECE9B1FE924 ON period (period_group_id)');
        $this->addSql('ALTER TABLE program ADD period_group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED77849B1FE924 FOREIGN KEY (period_group_id) REFERENCES period_group (id)');
        $this->addSql('CREATE INDEX IDX_92ED77849B1FE924 ON program (period_group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE period_group DROP FOREIGN KEY FK_5C68CCA0D2EECC3F');
        $this->addSql('ALTER TABLE period_group DROP FOREIGN KEY FK_5C68CCA0B03A8386');
        $this->addSql('ALTER TABLE period_group DROP FOREIGN KEY FK_5C68CCA0F5A2E305');
        $this->addSql('ALTER TABLE period_group DROP FOREIGN KEY FK_5C68CCA0E562D849');
        $this->addSql('ALTER TABLE period_type DROP FOREIGN KEY FK_4AD8DD87B03A8386');
        $this->addSql('ALTER TABLE period_type DROP FOREIGN KEY FK_4AD8DD87F5A2E305');
        $this->addSql('ALTER TABLE period_type DROP FOREIGN KEY FK_4AD8DD87E562D849');
        $this->addSql('DROP TABLE period_group');
        $this->addSql('DROP TABLE period_type');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECE3EA529CB');
        $this->addSql('ALTER TABLE period DROP FOREIGN KEY FK_C5B81ECE9B1FE924');
        $this->addSql('DROP INDEX IDX_C5B81ECE3EA529CB ON period');
        $this->addSql('DROP INDEX IDX_C5B81ECE9B1FE924 ON period');
        $this->addSql('ALTER TABLE period DROP period_type_id, DROP period_group_id');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED77849B1FE924');
        $this->addSql('DROP INDEX IDX_92ED77849B1FE924 ON program');
        $this->addSql('ALTER TABLE program DROP period_group_id');
    }
}
