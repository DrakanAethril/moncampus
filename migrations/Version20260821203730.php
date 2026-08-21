<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La console des machines : une ligne par console ouverte.
 *
 * Une trace, pas une session : le terminal vit dans le tmux de la machine et survit à tout ce que
 * cette table fait. Ce qui est enregistré ici, c'est qui a ouvert une console sur quoi, quand, et
 * sous quelle identité.
 */
final class Version20260821203730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console des machines : la table des sessions ouvertes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE console_session (id INT AUTO_INCREMENT NOT NULL, node VARCHAR(64) NOT NULL, vmid INT NOT NULL, guest_name VARCHAR(128) DEFAULT NULL, ip VARCHAR(45) NOT NULL, unix_user VARCHAR(32) NOT NULL, opened_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, guest_account_id INT DEFAULT NULL, host_id INT NOT NULL, opened_by_id INT NOT NULL, INDEX IDX_26353B63A6730F2B (guest_account_id), INDEX IDX_26353B631FB8D185 (host_id), INDEX IDX_26353B63AB159F5 (opened_by_id), INDEX idx_console_session_machine (host_id, vmid), INDEX idx_console_session_open (closed_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE console_session ADD CONSTRAINT FK_26353B63A6730F2B FOREIGN KEY (guest_account_id) REFERENCES guest_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE console_session ADD CONSTRAINT FK_26353B631FB8D185 FOREIGN KEY (host_id) REFERENCES proxmox_host (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE console_session ADD CONSTRAINT FK_26353B63AB159F5 FOREIGN KEY (opened_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE console_session DROP FOREIGN KEY FK_26353B63A6730F2B');
        $this->addSql('ALTER TABLE console_session DROP FOREIGN KEY FK_26353B631FB8D185');
        $this->addSql('ALTER TABLE console_session DROP FOREIGN KEY FK_26353B63AB159F5');
        $this->addSql('DROP TABLE console_session');
    }
}
