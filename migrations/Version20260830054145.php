<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `sequence_folder`: the classement of a teacher's sequence library, and the nullable `folder_id`
 * that files a séquence into one (App\Entity\SequenceFolder).
 *
 * Nothing is backfilled and nothing needs to be: a null `folder_id` **is** the root of the library,
 * so every existing séquence stays exactly where its teacher left it and the screen looks the same
 * until somebody creates a first folder.
 *
 * `ON DELETE SET NULL` on `folder_id` is a last resort, not the rule: deleting a folder promotes its
 * content one level up (App\Service\SequenceFolderManager::delete()), because a SequenceTemplate is
 * hard-deleted in this application and there is no corbeille to fish one out of.
 */
final class Version20260830054145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Folders for the sequence library - one tree per teacher, and a séquence's place in it";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sequence_folder (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(768) NOT NULL, depth SMALLINT UNSIGNED NOT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, parent_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_88B22DA47E3C61F9 (owner_id), INDEX IDX_88B22DA4727ACA70 (parent_id), INDEX IDX_88B22DA4B03A8386 (created_by_id), INDEX IDX_88B22DA4F5A2E305 (inactivated_by_id), INDEX IDX_88B22DA4E562D849 (last_updated_by_id), INDEX idx_sequence_folder_owner_parent (owner_id, parent_id), INDEX idx_sequence_folder_path (path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE sequence_folder ADD CONSTRAINT FK_88B22DA47E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_folder ADD CONSTRAINT FK_88B22DA4727ACA70 FOREIGN KEY (parent_id) REFERENCES sequence_folder (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sequence_folder ADD CONSTRAINT FK_88B22DA4B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sequence_folder ADD CONSTRAINT FK_88B22DA4F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sequence_folder ADD CONSTRAINT FK_88B22DA4E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE sequence_template ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sequence_template ADD CONSTRAINT FK_1F5C4FAC162CB942 FOREIGN KEY (folder_id) REFERENCES sequence_folder (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_1F5C4FAC162CB942 ON sequence_template (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sequence_folder DROP FOREIGN KEY FK_88B22DA47E3C61F9');
        $this->addSql('ALTER TABLE sequence_folder DROP FOREIGN KEY FK_88B22DA4727ACA70');
        $this->addSql('ALTER TABLE sequence_folder DROP FOREIGN KEY FK_88B22DA4B03A8386');
        $this->addSql('ALTER TABLE sequence_folder DROP FOREIGN KEY FK_88B22DA4F5A2E305');
        $this->addSql('ALTER TABLE sequence_folder DROP FOREIGN KEY FK_88B22DA4E562D849');
        $this->addSql('DROP TABLE sequence_folder');
        $this->addSql('ALTER TABLE sequence_template DROP FOREIGN KEY FK_1F5C4FAC162CB942');
        $this->addSql('DROP INDEX IDX_1F5C4FAC162CB942 ON sequence_template');
        $this->addSql('ALTER TABLE sequence_template DROP folder_id');
    }
}
