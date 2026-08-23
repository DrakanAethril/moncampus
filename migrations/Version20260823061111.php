<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le classement de la bibliothèque de quiz : la table des dossiers, et le dossier d'un quiz.
 */
final class Version20260823061111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz library folders: the quiz_folder tree and quiz_template.folder_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_folder (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(768) NOT NULL, depth SMALLINT UNSIGNED NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, parent_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_1E6F9947E3C61F9 (owner_id), INDEX IDX_1E6F994727ACA70 (parent_id), INDEX IDX_1E6F994B03A8386 (created_by_id), INDEX IDX_1E6F994F5A2E305 (inactivated_by_id), INDEX IDX_1E6F994E562D849 (last_updated_by_id), INDEX idx_quiz_folder_owner_parent (owner_id, parent_id), INDEX idx_quiz_folder_path (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_folder ADD CONSTRAINT FK_1E6F9947E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_folder ADD CONSTRAINT FK_1E6F994727ACA70 FOREIGN KEY (parent_id) REFERENCES quiz_folder (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_folder ADD CONSTRAINT FK_1E6F994B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_folder ADD CONSTRAINT FK_1E6F994F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_folder ADD CONSTRAINT FK_1E6F994E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE quiz_template ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_template ADD CONSTRAINT FK_41A4E6C6162CB942 FOREIGN KEY (folder_id) REFERENCES quiz_folder (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_41A4E6C6162CB942 ON quiz_template (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_folder DROP FOREIGN KEY FK_1E6F9947E3C61F9');
        $this->addSql('ALTER TABLE quiz_folder DROP FOREIGN KEY FK_1E6F994727ACA70');
        $this->addSql('ALTER TABLE quiz_folder DROP FOREIGN KEY FK_1E6F994B03A8386');
        $this->addSql('ALTER TABLE quiz_folder DROP FOREIGN KEY FK_1E6F994F5A2E305');
        $this->addSql('ALTER TABLE quiz_folder DROP FOREIGN KEY FK_1E6F994E562D849');
        $this->addSql('DROP TABLE quiz_folder');
        $this->addSql('ALTER TABLE quiz_template DROP FOREIGN KEY FK_41A4E6C6162CB942');
        $this->addSql('DROP INDEX IDX_41A4E6C6162CB942 ON quiz_template');
        $this->addSql('ALTER TABLE quiz_template DROP folder_id');
    }
}
