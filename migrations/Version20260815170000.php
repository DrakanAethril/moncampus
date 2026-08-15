<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base documentaire (design_handoff_base_documentaire) : articles, pièces jointes, tags partagés
 * et remises à zéro des compteurs de lecture.
 *
 * Le périmètre d'un article est une relation vers `group` : c'est la hiérarchie de groupes qui
 * porte « tout le campus > filière > classe », et rien n'en est dupliqué ici.
 */
final class Version20260815170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Base documentaire : articles, pièces jointes, tags et compteurs de lecture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE documentation_article (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, excerpt LONGTEXT NOT NULL, body LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, publish_start DATETIME DEFAULT NULL, publish_end DATETIME DEFAULT NULL, pinned TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, audiences JSON NOT NULL, read_count INT NOT NULL, read_count_since_reset INT NOT NULL, author_id INT NOT NULL, INDEX IDX_8067210EF675F31B (author_id), INDEX idx_documentation_article_status (status, published_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documentation_article_group (documentation_article_id INT NOT NULL, group_id INT NOT NULL, INDEX IDX_66971BA7339609A2 (documentation_article_id), INDEX IDX_66971BA7FE54D947 (group_id), PRIMARY KEY (documentation_article_id, group_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documentation_article_tag (documentation_article_id INT NOT NULL, documentation_tag_id INT NOT NULL, INDEX IDX_50B68BDD339609A2 (documentation_article_id), INDEX IDX_50B68BDDEF09CA47 (documentation_tag_id), PRIMARY KEY (documentation_article_id, documentation_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documentation_article_attachment (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(255) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(150) DEFAULT NULL, size_bytes INT DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, article_id INT NOT NULL, INDEX IDX_83C4039C7294869C (article_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documentation_counter_reset (id INT AUTO_INCREMENT NOT NULL, reset_at DATETIME NOT NULL, cleared_total INT NOT NULL, reset_by_id INT DEFAULT NULL, INDEX IDX_FFE4EB1C22185D26 (reset_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE documentation_tag (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, normalized_label VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_documentation_tag_normalized (normalized_label), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE documentation_article ADD CONSTRAINT FK_8067210EF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE documentation_article_group ADD CONSTRAINT FK_66971BA7339609A2 FOREIGN KEY (documentation_article_id) REFERENCES documentation_article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documentation_article_group ADD CONSTRAINT FK_66971BA7FE54D947 FOREIGN KEY (group_id) REFERENCES `group` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documentation_article_tag ADD CONSTRAINT FK_50B68BDD339609A2 FOREIGN KEY (documentation_article_id) REFERENCES documentation_article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documentation_article_tag ADD CONSTRAINT FK_50B68BDDEF09CA47 FOREIGN KEY (documentation_tag_id) REFERENCES documentation_tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documentation_article_attachment ADD CONSTRAINT FK_83C4039C7294869C FOREIGN KEY (article_id) REFERENCES documentation_article (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE documentation_counter_reset ADD CONSTRAINT FK_FFE4EB1C22185D26 FOREIGN KEY (reset_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documentation_article DROP FOREIGN KEY FK_8067210EF675F31B');
        $this->addSql('ALTER TABLE documentation_article_group DROP FOREIGN KEY FK_66971BA7339609A2');
        $this->addSql('ALTER TABLE documentation_article_group DROP FOREIGN KEY FK_66971BA7FE54D947');
        $this->addSql('ALTER TABLE documentation_article_tag DROP FOREIGN KEY FK_50B68BDD339609A2');
        $this->addSql('ALTER TABLE documentation_article_tag DROP FOREIGN KEY FK_50B68BDDEF09CA47');
        $this->addSql('ALTER TABLE documentation_article_attachment DROP FOREIGN KEY FK_83C4039C7294869C');
        $this->addSql('ALTER TABLE documentation_counter_reset DROP FOREIGN KEY FK_FFE4EB1C22185D26');
        $this->addSql('DROP TABLE documentation_article');
        $this->addSql('DROP TABLE documentation_article_group');
        $this->addSql('DROP TABLE documentation_article_tag');
        $this->addSql('DROP TABLE documentation_article_attachment');
        $this->addSql('DROP TABLE documentation_counter_reset');
        $this->addSql('DROP TABLE documentation_tag');
    }
}
