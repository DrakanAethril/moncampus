<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Console Proxmox, lot 4 - comptes invités, clé SSH de plateforme, lots de machines.
 *
 * **`guest_account` ne porte aucun mot de passe**, et c'est la décision structurante du lot : un
 * mot de passe est généré à la création du compte, affiché une seule fois sur un écran fait pour
 * être imprimé ou lu à voix haute, et oublié. Ce n'est tenable que parce que le réinitialiser tient
 * en un clic — la clé de plateforme rentre dans la machine sans le mot de passe de personne.
 * Stocker ces mots de passe reviendrait à détenir, pour chaque étudiant, un accès à la machine sur
 * laquelle il travaille, en échange d'un confort qu'un bouton fournit déjà.
 *
 * La machine y est désignée par (host_id, node, vmid) et non par une relation, pour la même raison
 * que dans `proxmox_operation` : Proxmox est la source de vérité et MonCampus ne stocke aucune
 * machine.
 *
 * `platform_ssh_key` est une table et non une variable d'environnement à cause de la ROTATION : la
 * remplacer, c'est poser la nouvelle clé, la vérifier, et seulement ensuite retirer l'ancienne — et
 * faire ça sur deux douzaines de machines prend assez de temps pour que les deux clés doivent
 * coexister. Une valeur unique dans l'environnement n'a pas la place de ce recouvrement, et sans
 * lui l'ordre ne peut pas être respecté sans risque : retirer d'abord, c'est se fermer la porte au
 * nez sur toutes les machines qui n'ont pas encore reçu la nouvelle.
 *
 * `vm_batch` ne touche au pédagogique que par DEUX clés étrangères - quel lot pour quelle classe,
 * quel compte pour quelle personne. Les deux tables de jointure (options, modalités) sont des
 * ensembles sans charge utile : vide = toute la classe, l'absence porte le sens, même convention
 * que le ciblage des documents partagés.
 *
 * `vm_batch_item` existe parce qu'un lot **n'est pas atomique** : vingt-quatre machines sont
 * vingt-quatre créations indépendantes, et un refus de l'hyperviseur ne doit pas défaire les
 * vingt-trois qui ont marché. Chaque ligne porte son état, ce qui est la seule chose qui donne un
 * sens à « reprendre ».
 */
final class Version20260818090528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console Proxmox : comptes invités, clé SSH de plateforme, lots de machines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guest_account (id INT AUTO_INCREMENT NOT NULL, node VARCHAR(64) NOT NULL, vmid INT NOT NULL, login VARCHAR(32) NOT NULL, display_name VARCHAR(180) DEFAULT NULL, sudo TINYINT NOT NULL, shell VARCHAR(64) NOT NULL, origin VARCHAR(20) NOT NULL, state VARCHAR(20) NOT NULL, synced_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, host_id INT NOT NULL, user_id INT DEFAULT NULL, batch_id INT DEFAULT NULL, INDEX IDX_3DF392E81FB8D185 (host_id), INDEX IDX_3DF392E8A76ED395 (user_id), INDEX IDX_3DF392E8F39EBE7A (batch_id), INDEX idx_guest_account_machine (host_id, vmid), UNIQUE INDEX uniq_guest_account_login (host_id, node, vmid, login), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE platform_ssh_key (id INT AUTO_INCREMENT NOT NULL, public_key LONGTEXT NOT NULL, private_key_cipher LONGTEXT NOT NULL, fingerprint VARCHAR(64) NOT NULL, is_active TINYINT NOT NULL, creation_date DATETIME NOT NULL, retired_at DATETIME DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_9797F136B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vm_batch (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, shape VARCHAR(20) NOT NULL, template_vmid INT NOT NULL, node VARCHAR(64) NOT NULL, storage VARCHAR(64) NOT NULL, cores INT NOT NULL, memory_mib INT NOT NULL, disk_gib INT NOT NULL, linked_clone TINYINT NOT NULL, name_pattern VARCHAR(64) NOT NULL, post_install_script LONGTEXT DEFAULT NULL, grant_sudo TINYINT NOT NULL, expires_at DATE DEFAULT NULL, reminded_at DATETIME DEFAULT NULL, creation_date DATETIME NOT NULL, inactive_date DATETIME DEFAULT NULL, last_updated_date DATETIME DEFAULT NULL, program_id INT NOT NULL, host_id INT NOT NULL, ip_range_id INT NOT NULL, created_by_id INT NOT NULL, inactivated_by_id INT DEFAULT NULL, last_updated_by_id INT DEFAULT NULL, INDEX IDX_268D75B03EB8070A (program_id), INDEX IDX_268D75B01FB8D185 (host_id), INDEX IDX_268D75B0541C1DB6 (ip_range_id), INDEX IDX_268D75B0B03A8386 (created_by_id), INDEX IDX_268D75B0F5A2E305 (inactivated_by_id), INDEX IDX_268D75B0E562D849 (last_updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vm_batch_option (vm_batch_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_1400B020625E4928 (vm_batch_id), INDEX IDX_1400B020A7C41D6F (option_id), PRIMARY KEY (vm_batch_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vm_batch_modality (vm_batch_id INT NOT NULL, modality_id INT NOT NULL, INDEX IDX_8A59FFD0625E4928 (vm_batch_id), INDEX IDX_8A59FFD02D6D889B (modality_id), PRIMARY KEY (vm_batch_id, modality_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vm_batch_item (id INT AUTO_INCREMENT NOT NULL, student_label VARCHAR(180) NOT NULL, guest_name VARCHAR(64) NOT NULL, login VARCHAR(32) NOT NULL, vmid INT DEFAULT NULL, node VARCHAR(64) DEFAULT NULL, status VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, position INT NOT NULL, batch_id INT NOT NULL, student_id INT DEFAULT NULL, ip_allocation_id INT DEFAULT NULL, operation_id INT DEFAULT NULL, INDEX IDX_242B0648CB944F1A (student_id), INDEX IDX_242B0648ECB5DFFB (ip_allocation_id), INDEX IDX_242B064844AC3583 (operation_id), INDEX idx_vm_batch_item_batch (batch_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guest_account ADD CONSTRAINT FK_3DF392E81FB8D185 FOREIGN KEY (host_id) REFERENCES proxmox_host (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guest_account ADD CONSTRAINT FK_3DF392E8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE guest_account ADD CONSTRAINT FK_3DF392E8F39EBE7A FOREIGN KEY (batch_id) REFERENCES vm_batch (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE platform_ssh_key ADD CONSTRAINT FK_9797F136B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B03EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B01FB8D185 FOREIGN KEY (host_id) REFERENCES proxmox_host (id)');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B0541C1DB6 FOREIGN KEY (ip_range_id) REFERENCES ip_range (id)');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B0B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B0F5A2E305 FOREIGN KEY (inactivated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B0E562D849 FOREIGN KEY (last_updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE vm_batch_option ADD CONSTRAINT FK_1400B020625E4928 FOREIGN KEY (vm_batch_id) REFERENCES vm_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vm_batch_option ADD CONSTRAINT FK_1400B020A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vm_batch_modality ADD CONSTRAINT FK_8A59FFD0625E4928 FOREIGN KEY (vm_batch_id) REFERENCES vm_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vm_batch_modality ADD CONSTRAINT FK_8A59FFD02D6D889B FOREIGN KEY (modality_id) REFERENCES modality (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vm_batch_item ADD CONSTRAINT FK_242B0648F39EBE7A FOREIGN KEY (batch_id) REFERENCES vm_batch (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vm_batch_item ADD CONSTRAINT FK_242B0648CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vm_batch_item ADD CONSTRAINT FK_242B0648ECB5DFFB FOREIGN KEY (ip_allocation_id) REFERENCES ip_allocation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vm_batch_item ADD CONSTRAINT FK_242B064844AC3583 FOREIGN KEY (operation_id) REFERENCES proxmox_operation (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_account DROP FOREIGN KEY FK_3DF392E81FB8D185');
        $this->addSql('ALTER TABLE guest_account DROP FOREIGN KEY FK_3DF392E8A76ED395');
        $this->addSql('ALTER TABLE guest_account DROP FOREIGN KEY FK_3DF392E8F39EBE7A');
        $this->addSql('ALTER TABLE platform_ssh_key DROP FOREIGN KEY FK_9797F136B03A8386');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B03EB8070A');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B01FB8D185');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B0541C1DB6');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B0B03A8386');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B0F5A2E305');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B0E562D849');
        $this->addSql('ALTER TABLE vm_batch_option DROP FOREIGN KEY FK_1400B020625E4928');
        $this->addSql('ALTER TABLE vm_batch_option DROP FOREIGN KEY FK_1400B020A7C41D6F');
        $this->addSql('ALTER TABLE vm_batch_modality DROP FOREIGN KEY FK_8A59FFD0625E4928');
        $this->addSql('ALTER TABLE vm_batch_modality DROP FOREIGN KEY FK_8A59FFD02D6D889B');
        $this->addSql('ALTER TABLE vm_batch_item DROP FOREIGN KEY FK_242B0648F39EBE7A');
        $this->addSql('ALTER TABLE vm_batch_item DROP FOREIGN KEY FK_242B0648CB944F1A');
        $this->addSql('ALTER TABLE vm_batch_item DROP FOREIGN KEY FK_242B0648ECB5DFFB');
        $this->addSql('ALTER TABLE vm_batch_item DROP FOREIGN KEY FK_242B064844AC3583');
        $this->addSql('DROP TABLE guest_account');
        $this->addSql('DROP TABLE platform_ssh_key');
        $this->addSql('DROP TABLE vm_batch');
        $this->addSql('DROP TABLE vm_batch_option');
        $this->addSql('DROP TABLE vm_batch_modality');
        $this->addSql('DROP TABLE vm_batch_item');
    }
}
