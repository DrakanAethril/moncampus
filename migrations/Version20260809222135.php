<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The help centre's two tables (design_handoff_aide): sections, and the entries they hold - an
 * entry being an article, a frequently-asked question or a glossary term, all three of the same
 * shape and searched as one index.
 *
 * Both carry an `audiences` JSON list (teacher/staff/student/tutor) - who the text is written for,
 * which is not who may reach the screen: that stays a matter of roles, checked in App\Service\HelpAccess.
 */
final class Version20260809222135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rubrique d\'aide : tables help_section et help_article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE help_article (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(180) NOT NULL, summary LONGTEXT NOT NULL, body LONGTEXT DEFAULT NULL, audiences JSON NOT NULL, position INT NOT NULL, published TINYINT NOT NULL, view_count INT NOT NULL, helpful_yes_count INT NOT NULL, helpful_no_count INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, section_id INT NOT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_5C20F1BAD823E37A (section_id), INDEX IDX_5C20F1BA896DBBDE (updated_by_id), INDEX idx_help_article_kind (kind, published), UNIQUE INDEX uniq_help_article_slug (section_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE help_section (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(120) NOT NULL, description VARCHAR(255) NOT NULL, audiences JSON NOT NULL, position INT NOT NULL, published TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_73698533896DBBDE (updated_by_id), UNIQUE INDEX uniq_help_section_slug (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE help_article ADD CONSTRAINT FK_5C20F1BAD823E37A FOREIGN KEY (section_id) REFERENCES help_section (id)');
        $this->addSql('ALTER TABLE help_article ADD CONSTRAINT FK_5C20F1BA896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE help_section ADD CONSTRAINT FK_73698533896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE help_article DROP FOREIGN KEY FK_5C20F1BAD823E37A');
        $this->addSql('ALTER TABLE help_article DROP FOREIGN KEY FK_5C20F1BA896DBBDE');
        $this->addSql('ALTER TABLE help_section DROP FOREIGN KEY FK_73698533896DBBDE');
        $this->addSql('DROP TABLE help_article');
        $this->addSql('DROP TABLE help_section');
    }
}
