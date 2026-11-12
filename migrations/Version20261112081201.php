<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * School-year import (design/validated/school-year-import.md): the import journal, the formation a
 * formation was imported from, and the « À relire » marks on shifted dates.
 */
final class Version20261112081201 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Import d'année scolaire : journal des imports, formation d'origine et dates à relire";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE school_year_import (id INT AUTO_INCREMENT NOT NULL, choices JSON NOT NULL, report JSON NOT NULL, creation_date DATETIME NOT NULL, source_id INT NOT NULL, target_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_D65CD5F4953C1C61 (source_id), INDEX IDX_D65CD5F4158E0B66 (target_id), INDEX IDX_D65CD5F4B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE school_year_import ADD CONSTRAINT FK_D65CD5F4953C1C61 FOREIGN KEY (source_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE school_year_import ADD CONSTRAINT FK_D65CD5F4158E0B66 FOREIGN KEY (target_id) REFERENCES school_year (id)');
        $this->addSql('ALTER TABLE school_year_import ADD CONSTRAINT FK_D65CD5F4B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE evaluation_period ADD shifted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE internship_evaluation_period ADD shifted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE period ADD shifted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD portfolio_dates_shifted_at DATETIME DEFAULT NULL, ADD imported_from_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE program ADD CONSTRAINT FK_92ED7784AC328554 FOREIGN KEY (imported_from_id) REFERENCES program (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_92ED7784AC328554 ON program (imported_from_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE school_year_import DROP FOREIGN KEY FK_D65CD5F4953C1C61');
        $this->addSql('ALTER TABLE school_year_import DROP FOREIGN KEY FK_D65CD5F4158E0B66');
        $this->addSql('ALTER TABLE school_year_import DROP FOREIGN KEY FK_D65CD5F4B03A8386');
        $this->addSql('DROP TABLE school_year_import');
        $this->addSql('ALTER TABLE evaluation_period DROP shifted_at');
        $this->addSql('ALTER TABLE internship_evaluation_period DROP shifted_at');
        $this->addSql('ALTER TABLE period DROP shifted_at');
        $this->addSql('ALTER TABLE program DROP FOREIGN KEY FK_92ED7784AC328554');
        $this->addSql('DROP INDEX IDX_92ED7784AC328554 ON program');
        $this->addSql('ALTER TABLE program DROP portfolio_dates_shifted_at, DROP imported_from_id');
    }
}
