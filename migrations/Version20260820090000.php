<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une machine par groupe, avec un compte par membre : le lot peut désormais être planifié depuis un
 * ensemble de groupes enregistrés plutôt que depuis la liste de la classe.
 *
 * `vm_batch.group_batch_id` est SET NULL et non CASCADE : supprimer l'ensemble de groupes ne doit
 * pas supprimer un lot dont les machines existent. Ce que chaque machine porte est figé dans
 * `vm_batch_item.group_members`, un instantané pris au moment du plan.
 *
 * La colonne JSON est ajoutée nullable puis remplie puis passée NOT NULL, plutôt qu'avec un DEFAULT :
 * un DEFAULT ne sert que le temps de l'ALTER et resterait ensuite comme une dérive de schéma que
 * doctrine:schema:validate signalerait à chaque exécution.
 */
final class Version20260820090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lots de VM par groupe : lien vers les groupes enregistrés et membres de chaque machine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch ADD group_batch_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vm_batch ADD CONSTRAINT FK_268D75B0FAAEF205 FOREIGN KEY (group_batch_id) REFERENCES group_batch (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_268D75B0FAAEF205 ON vm_batch (group_batch_id)');

        $this->addSql('ALTER TABLE vm_batch_item ADD group_members JSON DEFAULT NULL');
        $this->addSql("UPDATE vm_batch_item SET group_members = '[]' WHERE group_members IS NULL");
        $this->addSql('ALTER TABLE vm_batch_item MODIFY group_members JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch_item DROP group_members');
        $this->addSql('ALTER TABLE vm_batch DROP FOREIGN KEY FK_268D75B0FAAEF205');
        $this->addSql('DROP INDEX IDX_268D75B0FAAEF205 ON vm_batch');
        $this->addSql('ALTER TABLE vm_batch DROP group_batch_id');
    }
}
