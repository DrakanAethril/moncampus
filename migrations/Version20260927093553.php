<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927093553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Connecteur Claude : clients OAuth, autorisations, codes et jetons';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth_authorization_code (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(16) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, redirect_uri VARCHAR(2048) NOT NULL, code_challenge VARCHAR(128) NOT NULL, resource VARCHAR(2048) DEFAULT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, grant_id INT NOT NULL, INDEX IDX_793B08175C0C89F3 (grant_id), UNIQUE INDEX uniq_oauth_authorization_code_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth_client (id INT AUTO_INCREMENT NOT NULL, client_id VARCHAR(64) NOT NULL, client_name VARCHAR(200) NOT NULL, redirect_uris JSON NOT NULL, created_at DATETIME NOT NULL, created_ip VARCHAR(45) DEFAULT NULL, UNIQUE INDEX uniq_oauth_client_client_id (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth_grant (id INT AUTO_INCREMENT NOT NULL, scope VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, client_id INT NOT NULL, INDEX IDX_4E068C5DA76ED395 (user_id), INDEX IDX_4E068C5D19EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE oauth_token (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(10) NOT NULL, selector VARCHAR(16) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, rotated_at DATETIME DEFAULT NULL, grant_id INT NOT NULL, INDEX IDX_D8344B2A5C0C89F3 (grant_id), INDEX idx_oauth_token_expires_at (expires_at), UNIQUE INDEX uniq_oauth_token_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE oauth_authorization_code ADD CONSTRAINT FK_793B08175C0C89F3 FOREIGN KEY (grant_id) REFERENCES oauth_grant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_grant ADD CONSTRAINT FK_4E068C5DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_grant ADD CONSTRAINT FK_4E068C5D19EB6921 FOREIGN KEY (client_id) REFERENCES oauth_client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE oauth_token ADD CONSTRAINT FK_D8344B2A5C0C89F3 FOREIGN KEY (grant_id) REFERENCES oauth_grant (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_authorization_code DROP FOREIGN KEY FK_793B08175C0C89F3');
        $this->addSql('ALTER TABLE oauth_grant DROP FOREIGN KEY FK_4E068C5DA76ED395');
        $this->addSql('ALTER TABLE oauth_grant DROP FOREIGN KEY FK_4E068C5D19EB6921');
        $this->addSql('ALTER TABLE oauth_token DROP FOREIGN KEY FK_D8344B2A5C0C89F3');
        $this->addSql('DROP TABLE oauth_authorization_code');
        $this->addSql('DROP TABLE oauth_client');
        $this->addSql('DROP TABLE oauth_grant');
        $this->addSql('DROP TABLE oauth_token');
    }
}
