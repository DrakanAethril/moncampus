<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The file library (design/validated/file-library.md).
 *
 * One table for folders and files, told apart by `type` - the same choice the wiki made, for the
 * same reason: moving a file into a folder and walking the tree for a breadcrumb are one-list
 * operations with a discriminator, and two-list merges without one.
 *
 * `path` holds the ancestors' ids ('/12/48/'), so "everything under folder 48" is one LIKE and a
 * subtree move is a single UPDATE. VARCHAR(768) is the largest InnoDB indexes under utf8mb4.
 *
 * **The sibling-name uniqueness is deliberately not an index.** MySQL treats every NULL as distinct,
 * so UNIQUE (owner_id, parent_id, name) would constrain nothing at root level - the level a user
 * meets first. `idx_flib_owner_parent` is a lookup index for the question
 * App\Service\FileLibraryTree::uniqueName() actually asks. `storage_key` *is* unique, because two
 * rows pointing at one object would make Remplacer and the deferred deletion lie.
 *
 * `user.file_library_quota_bytes` is nullable and carries **no DEFAULT**: null means "whatever the
 * platform currently says" (FILE_LIBRARY_DEFAULT_QUOTA), which is what lets the default be raised
 * later for everyone who was never overridden.
 */
final class Version20260817043052 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bibliothèque de fichiers : file_library_node et le quota par compte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file_library_node (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, path VARCHAR(768) NOT NULL, depth SMALLINT UNSIGNED NOT NULL, storage_key VARCHAR(255) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(190) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, checksum VARCHAR(64) DEFAULT NULL, duration_seconds INT DEFAULT NULL, poster_storage_key VARCHAR(255) DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, last_updated_date DATETIME DEFAULT NULL, owner_id INT NOT NULL, parent_id INT DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_802E99A27E3C61F9 (owner_id), INDEX IDX_802E99A2727ACA70 (parent_id), INDEX IDX_802E99A2B03A8386 (created_by_id), INDEX IDX_802E99A2F5A2E305 (inactivated_by_id), INDEX IDX_802E99A2E562D849 (last_updated_by_id), INDEX idx_flib_owner_parent (owner_id, parent_id), INDEX idx_flib_owner_type (owner_id, type), INDEX idx_flib_path (path), UNIQUE INDEX uniq_flib_storage_key (storage_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE file_library_node ADD CONSTRAINT FK_802E99A27E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_library_node ADD CONSTRAINT FK_802E99A2727ACA70 FOREIGN KEY (parent_id) REFERENCES file_library_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE file_library_node ADD CONSTRAINT FK_802E99A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE file_library_node ADD CONSTRAINT FK_802E99A2F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE file_library_node ADD CONSTRAINT FK_802E99A2E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE user ADD file_library_quota_bytes BIGINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_library_node DROP FOREIGN KEY FK_802E99A27E3C61F9');
        $this->addSql('ALTER TABLE file_library_node DROP FOREIGN KEY FK_802E99A2727ACA70');
        $this->addSql('ALTER TABLE file_library_node DROP FOREIGN KEY FK_802E99A2B03A8386');
        $this->addSql('ALTER TABLE file_library_node DROP FOREIGN KEY FK_802E99A2F5A2E305');
        $this->addSql('ALTER TABLE file_library_node DROP FOREIGN KEY FK_802E99A2E562D849');
        $this->addSql('DROP TABLE file_library_node');
        $this->addSql('ALTER TABLE `user` DROP file_library_quota_bytes');
    }
}
