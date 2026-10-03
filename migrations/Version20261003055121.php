<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003055121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connecteur Claude : adresses d’envoi de fichier (file_upload_url) ; vignette des cours en ligne';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mcp_upload_slot (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, size_bytes BIGINT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, selector VARCHAR(16) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, grant_id INT NOT NULL, folder_id INT DEFAULT NULL, file_id INT DEFAULT NULL, INDEX IDX_2E4931F15C0C89F3 (grant_id), INDEX IDX_2E4931F1162CB942 (folder_id), INDEX IDX_2E4931F193CB796C (file_id), INDEX idx_mcp_upload_slot_expires_at (expires_at), UNIQUE INDEX uniq_mcp_upload_slot_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mcp_upload_slot ADD CONSTRAINT FK_2E4931F15C0C89F3 FOREIGN KEY (grant_id) REFERENCES oauth_grant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mcp_upload_slot ADD CONSTRAINT FK_2E4931F1162CB942 FOREIGN KEY (folder_id) REFERENCES file_library_node (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mcp_upload_slot ADD CONSTRAINT FK_2E4931F193CB796C FOREIGN KEY (file_id) REFERENCES file_library_node (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE online_course ADD image_key VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE mcp_upload_slot DROP FOREIGN KEY FK_2E4931F15C0C89F3');
        $this->addSql('ALTER TABLE mcp_upload_slot DROP FOREIGN KEY FK_2E4931F1162CB942');
        $this->addSql('ALTER TABLE mcp_upload_slot DROP FOREIGN KEY FK_2E4931F193CB796C');
        $this->addSql('DROP TABLE mcp_upload_slot');
        $this->addSql('ALTER TABLE online_course DROP image_key');
    }
}
