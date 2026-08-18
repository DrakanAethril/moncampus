<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console Proxmox, lot 3 - les plages d'adresses et leur registre.
 *
 * **`uniq_ip_allocation_live (range_id, live_key)` est la garantie de tout le lot**, et sa forme
 * mérite l'explication : MySQL 8 n'a pas d'index unique partiel, or ce qui doit être unique n'est
 * pas « (plage, adresse) » mais « (plage, adresse) parmi les lignes VIVANTES » - une adresse libérée
 * reste en base, c'est l'histoire de qui a tenu quoi. D'où la colonne `live_key` : elle vaut
 * l'adresse tant que l'allocation vit et NULL une fois libérée, et deux NULL n'entrent jamais en
 * collision dans un index unique. Seul `IpAllocation::setStatus()` l'écrit, ce qui est ce qui tient
 * les deux colonnes d'accord.
 *
 * Pourquoi la base et pas PHP : deux administrateurs lançant un lot dans la même seconde lisent tous
 * les deux « la prochaine libre est la .57 ». Un SELECT suivi d'un INSERT perd cette course dès
 * qu'on la joue assez souvent, et un conflit d'adresses met des semaines à être relié à sa cause.
 * `App\Service\Network\IpAllocator` prend en plus un verrou d'écriture sur la ligne de la PLAGE
 * avant de lire l'occupation - le verrou fait que la collision n'arrive pas, l'index fait qu'elle ne
 * peut pas être arrivée.
 *
 * `first_usable` / `last_usable` sont distincts du CIDR, et c'est le couple de champs le plus utile
 * de l'écran : un /24 porte 254 adresses, mais les .1 à .49 sont la passerelle, les commutateurs et
 * tout ce qui est adressé à la main. Sans eux, la console finirait par proposer la passerelle.
 *
 * `bridge` et `vlan` ne sont pas décoratifs non plus : rattacher une machine à une plage demande
 * DEUX critères - l'adresse dans le CIDR ET l'interface sur le bon pont avec la bonne balise - sinon
 * deux plages en 10.30.x sur des VLAN différents se mélangent.
 *
 * `ON DELETE CASCADE` sur `range_id` : une allocation n'est rien sans sa plage. `SET NULL` sur
 * `operation_id` pour la raison inverse - le journal peut être purgé, l'adresse reste attribuée.
 */
final class Version20260818081426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console Proxmox : plages d’adresses (ip_range) et registre des allocations (ip_allocation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ip_allocation (id INT AUTO_INCREMENT NOT NULL, ip VARCHAR(45) NOT NULL, live_key VARCHAR(45) DEFAULT NULL, hostname VARCHAR(64) DEFAULT NULL, mac_address VARCHAR(17) DEFAULT NULL, vmid INT DEFAULT NULL, node VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, origin VARCHAR(20) NOT NULL, note LONGTEXT DEFAULT NULL, reserved_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, released_at DATETIME DEFAULT NULL, range_id INT NOT NULL, operation_id INT DEFAULT NULL, INDEX IDX_F6D0757E44AC3583 (operation_id), INDEX idx_ip_allocation_range (range_id), INDEX idx_ip_allocation_status (status), UNIQUE INDEX uniq_ip_allocation_live (range_id, live_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ip_range (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, cidr VARCHAR(43) NOT NULL, gateway VARCHAR(45) NOT NULL, bridge VARCHAR(32) NOT NULL, vlan SMALLINT DEFAULT NULL, first_usable VARCHAR(45) NOT NULL, last_usable VARCHAR(45) NOT NULL, note LONGTEXT DEFAULT NULL, last_scan_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, host_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_EE93A3A41FB8D185 (host_id), INDEX IDX_EE93A3A4B03A8386 (created_by_id), INDEX IDX_EE93A3A4F5A2E305 (inactivated_by_id), INDEX IDX_EE93A3A4E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ip_allocation ADD CONSTRAINT FK_F6D0757E2A82D0B1 FOREIGN KEY (range_id) REFERENCES ip_range (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ip_allocation ADD CONSTRAINT FK_F6D0757E44AC3583 FOREIGN KEY (operation_id) REFERENCES proxmox_operation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ip_range ADD CONSTRAINT FK_EE93A3A41FB8D185 FOREIGN KEY (host_id) REFERENCES proxmox_host (id)');
        $this->addSql('ALTER TABLE ip_range ADD CONSTRAINT FK_EE93A3A4B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE ip_range ADD CONSTRAINT FK_EE93A3A4F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE ip_range ADD CONSTRAINT FK_EE93A3A4E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ip_allocation DROP FOREIGN KEY FK_F6D0757E2A82D0B1');
        $this->addSql('ALTER TABLE ip_allocation DROP FOREIGN KEY FK_F6D0757E44AC3583');
        $this->addSql('ALTER TABLE ip_range DROP FOREIGN KEY FK_EE93A3A41FB8D185');
        $this->addSql('ALTER TABLE ip_range DROP FOREIGN KEY FK_EE93A3A4B03A8386');
        $this->addSql('ALTER TABLE ip_range DROP FOREIGN KEY FK_EE93A3A4F5A2E305');
        $this->addSql('ALTER TABLE ip_range DROP FOREIGN KEY FK_EE93A3A4E562D849');
        $this->addSql('DROP TABLE ip_allocation');
        $this->addSql('DROP TABLE ip_range');
    }
}
