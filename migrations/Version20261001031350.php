<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Trouver une entreprise »: the one list of company categories every student filters in
 * (design/validated/vivier-entreprises.md, D5). Empty on arrival - the screen offers a starting
 * list to the administrator.
 */
final class Version20261001031350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company search categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE company_search_category (id INT AUTO_INCREMENT NOT NULL, theme VARCHAR(20) NOT NULL, label VARCHAR(120) NOT NULL, hint VARCHAR(255) DEFAULT NULL, naf_codes JSON NOT NULL, flag VARCHAR(30) DEFAULT NULL, minimum_band VARCHAR(10) DEFAULT NULL, position INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_59EA8809B03A8386 (created_by_id), INDEX IDX_59EA8809896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE company_search_category ADD CONSTRAINT FK_59EA8809B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE company_search_category ADD CONSTRAINT FK_59EA8809896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company_search_category DROP FOREIGN KEY FK_59EA8809B03A8386');
        $this->addSql('ALTER TABLE company_search_category DROP FOREIGN KEY FK_59EA8809896DBBDE');
        $this->addSql('DROP TABLE company_search_category');
    }
}
