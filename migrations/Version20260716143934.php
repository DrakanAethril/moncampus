<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260716143934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add magic_login_token table for passwordless magic-link login';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE magic_login_token (id INT UNSIGNED AUTO_INCREMENT NOT NULL, selector VARCHAR(32) NOT NULL, verifier_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME DEFAULT NULL, request_ip VARCHAR(45) DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_B3F8789A76ED395 (user_id), UNIQUE INDEX uniq_magic_login_token_selector (selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE magic_login_token ADD CONSTRAINT FK_B3F8789A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE magic_login_token DROP FOREIGN KEY FK_B3F8789A76ED395');
        $this->addSql('DROP TABLE magic_login_token');
    }
}
