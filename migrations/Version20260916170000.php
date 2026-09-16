<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A dossier's formation targets can now be narrowed to one or more of the formation's options -
 * « SIO-2, les SLAM » rather than « SIO-2 ».
 *
 * `dossier_target_program` stops being a join table and becomes a row of its own, because the
 * narrowing belongs to the (dossier, formation) **pair** and not to the dossier: a flat list of
 * options beside a flat list of classes could only either empty the class that does not offer the
 * option, or quietly widen the one that does. See App\Entity\DossierTargetProgram.
 *
 * A separate migration rather than an edit of Version20260916150000, which created the join table a
 * few hours earlier: that one has already run on staging, and a migration that changes shape after
 * it has run is a migration whose replay no longer matches the databases it produced. The existing
 * rows survive - the pair keeps its unique index, and an empty option set is exactly what they all
 * mean today.
 */
final class Version20260916170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossiers documentaires : une cible de formation peut être restreinte à des options';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dossier_target_program ADD id INT AUTO_INCREMENT NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX dossier_target_program_unique ON dossier_target_program (dossier_id, program_id)');
        $this->addSql('CREATE TABLE dossier_target_option (target_id INT NOT NULL, option_id INT NOT NULL, INDEX IDX_49CF7106158E0B66 (target_id), INDEX IDX_49CF7106A7C41D6F (option_id), PRIMARY KEY (target_id, option_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dossier_target_option ADD CONSTRAINT FK_49CF7106158E0B66 FOREIGN KEY (target_id) REFERENCES dossier_target_program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_target_option ADD CONSTRAINT FK_49CF7106A7C41D6F FOREIGN KEY (option_id) REFERENCES `option` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // The narrowing has nowhere to go back to: a target restricted to an option becomes the
        // whole class again, which is what a join table of (dossier, program) can say.
        $this->addSql('DROP TABLE dossier_target_option');
        $this->addSql('DROP INDEX dossier_target_program_unique ON dossier_target_program');
        $this->addSql('ALTER TABLE dossier_target_program DROP id, DROP PRIMARY KEY, ADD PRIMARY KEY (dossier_id, program_id)');
    }
}
