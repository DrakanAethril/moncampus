<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console Proxmox, lot 1 - la seule table du socle : App\Entity\ProxmoxHost.
 *
 * Une seule table pour toute une infrastructure, et c'est délibéré : Proxmox est la source de
 * vérité et MonCampus n'en recopie rien. Aucune VM, aucun nœud, aucune image n'a de ligne ici ; les
 * écrans lisent l'API à l'affichage. Ce qui est stocké, c'est ce que Proxmox ne sait pas - la
 * déclaration d'un hôte - et, à partir du lot 2, le journal de ce que MonCampus a demandé.
 *
 * Deux colonnes chiffrées plutôt qu'une : `secret_cipher` porte le compte d'opération,
 * `provision_secret_cipher` le compte distinct qui porte VM.Allocate. Côté Proxmox il n'existe pas
 * de privilège séparé pour détruire - VM.Allocate autorise POST et DELETE de la même main - donc
 * séparer les deux comptes est ce qui fait que le compte du quotidien ne peut rien détruire. Un
 * `provision_secret_cipher` à NULL n'est pas un champ vide : c'est un hôte en lecture et pilotage
 * seuls.
 *
 * Les deux colonnes sont du LONGTEXT et non du VARCHAR : le format scellé est
 * `v1.<nonce>.<chiffré>` en base64, et la version en tête existe pour qu'une rotation de clé puisse
 * lire l'ancien format tout en écrivant le nouveau.
 *
 * `tls_ca_pem` n'est PAS chiffrée - un certificat d'autorité est public par construction. Seules
 * les deux colonnes nommées ci-dessus le sont.
 *
 * Les trois compteurs `last_*_count` sont l'instantané du dernier test, pas un cache : la
 * conception fige « l'état d'un hôte est le dernier test connu, horodaté, jamais sondé à
 * l'affichage », et sonder N hyperviseurs pour dessiner une liste la rend aussi lente que le pire
 * d'entre eux. Ils sont remis à NULL quand l'hôte cesse de répondre.
 *
 * Pas de suppression : `inactive_date` désactive, comme partout dans Paramètres. Le journal des
 * opérations pointera vers ces lignes, et une ligne supprimée emporterait son histoire.
 */
final class Version20260818072158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console Proxmox : déclaration des hôtes (proxmox_host), secrets chiffrés et périmètre';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE proxmox_host (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, hostname VARCHAR(255) NOT NULL, port SMALLINT UNSIGNED NOT NULL, credential_kind VARCHAR(20) NOT NULL, realm VARCHAR(32) NOT NULL, username VARCHAR(120) NOT NULL, token_name VARCHAR(64) DEFAULT NULL, secret_cipher LONGTEXT NOT NULL, secret_rotated_at DATETIME DEFAULT NULL, provision_username VARCHAR(120) DEFAULT NULL, provision_realm VARCHAR(32) DEFAULT NULL, provision_token_name VARCHAR(64) DEFAULT NULL, provision_secret_cipher LONGTEXT DEFAULT NULL, tls_mode VARCHAR(20) NOT NULL, tls_ca_pem LONGTEXT DEFAULT NULL, tls_pin_sha256 VARCHAR(64) DEFAULT NULL, managed_pool VARCHAR(64) DEFAULT NULL, vmid_min INT DEFAULT NULL, vmid_max INT DEFAULT NULL, allow_start TINYINT NOT NULL, allow_stop TINYINT NOT NULL, allow_create TINYINT NOT NULL, max_guests INT DEFAULT NULL, max_cores INT DEFAULT NULL, max_memory_mib INT DEFAULT NULL, max_disk_gib INT DEFAULT NULL, last_check_at DATETIME DEFAULT NULL, last_check_ok TINYINT DEFAULT NULL, last_check_message LONGTEXT DEFAULT NULL, pve_version VARCHAR(40) DEFAULT NULL, last_node_count INT DEFAULT NULL, last_guest_count INT DEFAULT NULL, last_running_count INT DEFAULT NULL, last_scan_at DATETIME DEFAULT NULL, position INT NOT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_170700A8B03A8386 (created_by_id), INDEX IDX_170700A8F5A2E305 (inactivated_by_id), INDEX IDX_170700A8E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE proxmox_host ADD CONSTRAINT FK_170700A8B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE proxmox_host ADD CONSTRAINT FK_170700A8F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE proxmox_host ADD CONSTRAINT FK_170700A8E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_host DROP FOREIGN KEY FK_170700A8B03A8386');
        $this->addSql('ALTER TABLE proxmox_host DROP FOREIGN KEY FK_170700A8F5A2E305');
        $this->addSql('ALTER TABLE proxmox_host DROP FOREIGN KEY FK_170700A8E562D849');
        $this->addSql('DROP TABLE proxmox_host');
    }
}
