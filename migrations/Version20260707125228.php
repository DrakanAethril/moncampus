<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260707125228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE internship_team_evaluation (id INT AUTO_INCREMENT NOT NULL, remarks_text LONGTEXT DEFAULT NULL, validation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, student_id INT NOT NULL, program_id INT NOT NULL, period_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_F853D12DCB944F1A (student_id), INDEX IDX_F853D12D3EB8070A (program_id), INDEX IDX_F853D12DEC8B7ADE (period_id), INDEX IDX_F853D12DB03A8386 (created_by_id), INDEX IDX_F853D12DF5A2E305 (inactivated_by_id), INDEX IDX_F853D12DE562D849 (last_updated_by_id), UNIQUE INDEX internship_team_evaluation_unique (student_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DCB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12D3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DEC8B7ADE FOREIGN KEY (period_id) REFERENCES period (id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DF5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE internship_team_evaluation ADD CONSTRAINT FK_F853D12DE562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DCB944F1A');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12D3EB8070A');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DEC8B7ADE');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DB03A8386');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DF5A2E305');
        $this->addSql('ALTER TABLE internship_team_evaluation DROP FOREIGN KEY FK_F853D12DE562D849');
        $this->addSql('DROP TABLE internship_team_evaluation');
    }
}
