<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops internship_team_evaluation: the teaching team's step was taken out of the Livret
 * Alternant's signature chain, which now runs tutor -> apprentice -> formation centre. Nothing
 * reads the table any more - not the wizard, not the booklet PDF - so the rows it still holds go
 * with it. down() re-creates the structure, never the data.
 */
final class Version20260907190642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop internship_team_evaluation - the teaching team no longer signs an evaluation period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12D3E8BB15A`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12D3EB8070A`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DB03A8386`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DCB944F1A`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DD2EDD3FB`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DE562D849`');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY `FK_F853D12DF5A2E305`');
        $this->addSql('DROP TABLE internship_team_evaluation');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE internship_team_evaluation (id INT AUTO_INCREMENT NOT NULL, remarks_text LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, validation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, student_id INT NOT NULL, program_id INT NOT NULL, evaluation_period_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, signed_at DATETIME DEFAULT NULL, signed_by_id INT DEFAULT NULL, INDEX IDX_F853D12D3E8BB15A (evaluation_period_id), INDEX IDX_F853D12D3EB8070A (program_id), INDEX IDX_F853D12DB03A8386 (created_by_id), INDEX IDX_F853D12DCB944F1A (student_id), INDEX IDX_F853D12DD2EDD3FB (signed_by_id), INDEX IDX_F853D12DE562D849 (last_updated_by_id), INDEX IDX_F853D12DF5A2E305 (inactivated_by_id), UNIQUE INDEX internship_team_evaluation_unique (student_id, evaluation_period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12D3E8BB15A` FOREIGN KEY (evaluation_period_id) REFERENCES internship_evaluation_period (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12D3EB8070A` FOREIGN KEY (program_id) REFERENCES program (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DB03A8386` FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DCB944F1A` FOREIGN KEY (student_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DD2EDD3FB` FOREIGN KEY (signed_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DE562D849` FOREIGN KEY (last_updated_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT `FK_F853D12DF5A2E305` FOREIGN KEY (inactivated_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
