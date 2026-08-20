<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The public SSH keys an account owns, so the machines this application creates let their owner in.
 */
final class Version20260820103603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates user_ssh_key: the public keys an account owns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_ssh_key (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, public_key LONGTEXT NOT NULL, fingerprint VARCHAR(64) NOT NULL, creation_date DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_DAAA256EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_ssh_key ADD CONSTRAINT FK_DAAA256EA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_ssh_key DROP FOREIGN KEY FK_DAAA256EA76ED395');
        $this->addSql('DROP TABLE user_ssh_key');
    }
}
