<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wiki (design/validated/wiki.md), lot 1: the four tables and their two join tables.
 *
 * Two things about the shape are deliberate and would read as omissions otherwise:
 *
 * - **no column carries a DEFAULT.** Defaults live in the PHP constructors; a DEFAULT here would
 *   only serve the duration of the ALTER and then show up as drift in doctrine:schema:validate.
 * - **idx_wiki_node_slug is not unique.** Uniqueness of a slug among its siblings is enforced by
 *   App\Service\WikiTree::uniqueSlug(), because MySQL treats every NULL parent_id as distinct and
 *   a UNIQUE index would therefore enforce nothing at all for root-level nodes - exactly the level
 *   a user notices first.
 *
 * `path` is VARCHAR(768), the largest InnoDB can index under utf8mb4, which leaves room for roughly
 * eighty levels of nesting.
 */
final class Version20260816064054 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wiki : wikis, nœuds (dossiers et pages), pièces jointes, révisions, membres et classes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wiki (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, archived TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_22CDDC067E3C61F9 (owner_id), INDEX IDX_22CDDC06B03A8386 (created_by_id), INDEX idx_wiki_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_member (wiki_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_9445BF4AAA948DBE (wiki_id), INDEX IDX_9445BF4AA76ED395 (user_id), PRIMARY KEY (wiki_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_program (wiki_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_5ADE8741AA948DBE (wiki_id), INDEX IDX_5ADE87413EB8070A (program_id), PRIMARY KEY (wiki_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_attachment (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(150) DEFAULT NULL, size_bytes INT DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, node_id INT NOT NULL, INDEX IDX_45570D73460D9FD7 (node_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_node (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, position INT NOT NULL, path VARCHAR(768) NOT NULL, depth SMALLINT NOT NULL, body LONGTEXT DEFAULT NULL, body_text LONGTEXT DEFAULT NULL, deleted_at DATETIME DEFAULT NULL, locked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, wiki_id INT NOT NULL, parent_id INT DEFAULT NULL, locked_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_55D28ECAA948DBE (wiki_id), INDEX IDX_55D28EC727ACA70 (parent_id), INDEX IDX_55D28EC7A88E00 (locked_by_id), INDEX IDX_55D28ECB03A8386 (created_by_id), INDEX IDX_55D28EC896DBBDE (updated_by_id), INDEX idx_wiki_node_wiki (wiki_id, deleted_at), INDEX idx_wiki_node_path (path), INDEX idx_wiki_node_slug (wiki_id, parent_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE wiki_revision (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, node_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_86A51003460D9FD7 (node_id), INDEX IDX_86A51003F675F31B (author_id), INDEX idx_wiki_revision_node (node_id, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE wiki ADD CONSTRAINT FK_22CDDC067E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki ADD CONSTRAINT FK_22CDDC06B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wiki_member ADD CONSTRAINT FK_9445BF4AAA948DBE FOREIGN KEY (wiki_id) REFERENCES wiki (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_member ADD CONSTRAINT FK_9445BF4AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_program ADD CONSTRAINT FK_5ADE8741AA948DBE FOREIGN KEY (wiki_id) REFERENCES wiki (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_program ADD CONSTRAINT FK_5ADE87413EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_attachment ADD CONSTRAINT FK_45570D73460D9FD7 FOREIGN KEY (node_id) REFERENCES wiki_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_node ADD CONSTRAINT FK_55D28ECAA948DBE FOREIGN KEY (wiki_id) REFERENCES wiki (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_node ADD CONSTRAINT FK_55D28EC727ACA70 FOREIGN KEY (parent_id) REFERENCES wiki_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_node ADD CONSTRAINT FK_55D28EC7A88E00 FOREIGN KEY (locked_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wiki_node ADD CONSTRAINT FK_55D28ECB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wiki_node ADD CONSTRAINT FK_55D28EC896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE wiki_revision ADD CONSTRAINT FK_86A51003460D9FD7 FOREIGN KEY (node_id) REFERENCES wiki_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wiki_revision ADD CONSTRAINT FK_86A51003F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wiki DROP FOREIGN KEY FK_22CDDC067E3C61F9');
        $this->addSql('ALTER TABLE wiki DROP FOREIGN KEY FK_22CDDC06B03A8386');
        $this->addSql('ALTER TABLE wiki_member DROP FOREIGN KEY FK_9445BF4AAA948DBE');
        $this->addSql('ALTER TABLE wiki_member DROP FOREIGN KEY FK_9445BF4AA76ED395');
        $this->addSql('ALTER TABLE wiki_program DROP FOREIGN KEY FK_5ADE8741AA948DBE');
        $this->addSql('ALTER TABLE wiki_program DROP FOREIGN KEY FK_5ADE87413EB8070A');
        $this->addSql('ALTER TABLE wiki_attachment DROP FOREIGN KEY FK_45570D73460D9FD7');
        $this->addSql('ALTER TABLE wiki_node DROP FOREIGN KEY FK_55D28ECAA948DBE');
        $this->addSql('ALTER TABLE wiki_node DROP FOREIGN KEY FK_55D28EC727ACA70');
        $this->addSql('ALTER TABLE wiki_node DROP FOREIGN KEY FK_55D28EC7A88E00');
        $this->addSql('ALTER TABLE wiki_node DROP FOREIGN KEY FK_55D28ECB03A8386');
        $this->addSql('ALTER TABLE wiki_node DROP FOREIGN KEY FK_55D28EC896DBBDE');
        $this->addSql('ALTER TABLE wiki_revision DROP FOREIGN KEY FK_86A51003460D9FD7');
        $this->addSql('ALTER TABLE wiki_revision DROP FOREIGN KEY FK_86A51003F675F31B');
        $this->addSql('DROP TABLE wiki');
        $this->addSql('DROP TABLE wiki_member');
        $this->addSql('DROP TABLE wiki_program');
        $this->addSql('DROP TABLE wiki_attachment');
        $this->addSql('DROP TABLE wiki_node');
        $this->addSql('DROP TABLE wiki_revision');
    }
}
