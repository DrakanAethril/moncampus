<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console Proxmox, lot 2 - le journal des opérations (App\Entity\ProxmoxOperation).
 *
 * La ligne est écrite AVANT que l'appel ne parte, à `pending`. C'est tout le dessin : une opération
 * qui disparaît dans un réseau mort laisse quand même la trace de qui l'a demandée. Écrire la ligne
 * après la réponse perdrait exactement les cas qui valent d'être gardés.
 *
 * La machine est décrite par un **instantané** (`node`, `vmid`, `guest_name`, `guest_type`) et non
 * par une relation, parce qu'il n'y a aucune ligne vers laquelle pointer : Proxmox est la source de
 * vérité et MonCampus ne stocke aucune machine. Une VM détruite la semaine prochaine doit laisser
 * une ligne lisible ici, portant le nom qu'elle avait au moment de l'acte. `host_label` et
 * `requested_by_label` suivent la même règle, à côté de leurs clés étrangères en `SET NULL` : le
 * journal survit à ses deux références.
 *
 * `status` porte cinq valeurs, dont `unknown` : la demande est partie, l'hôte est tombé avant qu'on
 * puisse lire la tâche. Mentir dans un sens ou dans l'autre serait pire que l'avouer, et l'`upid`
 * conservé permet de retrouver la réponse dans Proxmox.
 *
 * `action` ne connaît pas de valeur `delete`, et ne doit jamais en connaître : l'application arrête
 * les machines, elle ne les détruit pas. Une action qu'on ne peut pas nommer ne peut pas être
 * journalisée, et une action qu'on ne peut pas journaliser n'a rien à faire ici.
 *
 * L'index sur `requested_at` sert la liste, celui sur `status` la reprise des opérations restées en
 * l'air, et celui sur `host_id` l'affichage des opérations en cours dans la liste des machines.
 */
final class Version20260818075451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console Proxmox : journal des opérations (proxmox_operation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE proxmox_operation (id INT AUTO_INCREMENT NOT NULL, host_label VARCHAR(120) NOT NULL, action VARCHAR(20) NOT NULL, node VARCHAR(64) DEFAULT NULL, vmid INT DEFAULT NULL, guest_name VARCHAR(128) DEFAULT NULL, guest_type VARCHAR(8) DEFAULT NULL, status VARCHAR(20) NOT NULL, upid VARCHAR(255) DEFAULT NULL, message LONGTEXT DEFAULT NULL, output LONGTEXT DEFAULT NULL, exit_code SMALLINT DEFAULT NULL, requested_by_label VARCHAR(180) NOT NULL, requested_at DATETIME NOT NULL, settled_at DATETIME DEFAULT NULL, host_id INT DEFAULT NULL, requested_by_id INT DEFAULT NULL, INDEX IDX_DA7217504DA1E751 (requested_by_id), INDEX idx_proxmox_operation_requested (requested_at), INDEX idx_proxmox_operation_host (host_id), INDEX idx_proxmox_operation_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE proxmox_operation ADD CONSTRAINT FK_DA7217501FB8D185 FOREIGN KEY (host_id) REFERENCES proxmox_host (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE proxmox_operation ADD CONSTRAINT FK_DA7217504DA1E751 FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_operation DROP FOREIGN KEY FK_DA7217501FB8D185');
        $this->addSql('ALTER TABLE proxmox_operation DROP FOREIGN KEY FK_DA7217504DA1E751');
        $this->addSql('DROP TABLE proxmox_operation');
    }
}
