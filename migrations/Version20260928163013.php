<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928163013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sessions des applications mobiles (jeton de renouvellement tournant)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mobile_session (id INT AUTO_INCREMENT NOT NULL, app VARCHAR(20) NOT NULL, current_selector VARCHAR(16) NOT NULL, current_verifier_hash VARCHAR(64) NOT NULL, previous_selector VARCHAR(16) DEFAULT NULL, previous_verifier_hash VARCHAR(64) DEFAULT NULL, rotated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_30EE1821A76ED395 (user_id), INDEX mobile_session_expires_at_idx (expires_at), UNIQUE INDEX mobile_session_current_selector_unique (current_selector), UNIQUE INDEX mobile_session_previous_selector_unique (previous_selector), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mobile_session ADD CONSTRAINT FK_30EE1821A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mobile_session DROP FOREIGN KEY FK_30EE1821A76ED395');
        $this->addSql('DROP TABLE mobile_session');
    }
}
