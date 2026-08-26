<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le classement de la bibliothèque de sondages : la table des dossiers, et le dossier d'un modèle.
 */
final class Version20260826115021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Survey library folders: the survey_folder tree and survey_template.folder_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE survey_folder (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(768) NOT NULL, depth SMALLINT UNSIGNED NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, parent_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_C4ACC1A17E3C61F9 (owner_id), INDEX IDX_C4ACC1A1727ACA70 (parent_id), INDEX IDX_C4ACC1A1B03A8386 (created_by_id), INDEX IDX_C4ACC1A1F5A2E305 (inactivated_by_id), INDEX IDX_C4ACC1A1E562D849 (last_updated_by_id), INDEX idx_survey_folder_owner_parent (owner_id, parent_id), INDEX idx_survey_folder_path (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE survey_folder ADD CONSTRAINT FK_C4ACC1A17E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_folder ADD CONSTRAINT FK_C4ACC1A1727ACA70 FOREIGN KEY (parent_id) REFERENCES survey_folder (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE survey_folder ADD CONSTRAINT FK_C4ACC1A1B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE survey_folder ADD CONSTRAINT FK_C4ACC1A1F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE survey_folder ADD CONSTRAINT FK_C4ACC1A1E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE survey_template ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE survey_template ADD CONSTRAINT FK_CB9759A4162CB942 FOREIGN KEY (folder_id) REFERENCES survey_folder (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CB9759A4162CB942 ON survey_template (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey_folder DROP FOREIGN KEY FK_C4ACC1A17E3C61F9');
        $this->addSql('ALTER TABLE survey_folder DROP FOREIGN KEY FK_C4ACC1A1727ACA70');
        $this->addSql('ALTER TABLE survey_folder DROP FOREIGN KEY FK_C4ACC1A1B03A8386');
        $this->addSql('ALTER TABLE survey_folder DROP FOREIGN KEY FK_C4ACC1A1F5A2E305');
        $this->addSql('ALTER TABLE survey_folder DROP FOREIGN KEY FK_C4ACC1A1E562D849');
        $this->addSql('DROP TABLE survey_folder');
        $this->addSql('ALTER TABLE survey_template DROP FOREIGN KEY FK_CB9759A4162CB942');
        $this->addSql('DROP INDEX IDX_CB9759A4162CB942 ON survey_template');
        $this->addSql('ALTER TABLE survey_template DROP folder_id');
    }
}
