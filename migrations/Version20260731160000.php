<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfills group_type.order, which Version20260727142823 added as `INT NOT NULL` without ever
 * giving existing rows a value - so every GroupType predating the drag-and-drop feature sits at 0.
 *
 * All-zero isn't merely cosmetic: the two screens that read this column tie-break differently
 * (GroupTypeRepository sorts group_type alone, GroupRepository::groupActiveByType() sorts it
 * joined to `group` and falls through to g.name), so the settings list and the user-creation
 * secondary-groups picker showed the same types in two different orders.
 *
 * Renumbers every row 1..N by (order, id) rather than only the zeroes: that keeps whatever
 * ordering staff already dragged into place, while removing the ties and the zeroes that let the
 * two queries disagree.
 */
final class Version20260731160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill group_type.order - contiguous 1..N, no ties, no zeroes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE group_type gt
            JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY `order` ASC, id ASC) AS position FROM group_type) ranked
                ON ranked.id = gt.id
            SET gt.`order` = ranked.position');
    }

    public function down(Schema $schema): void
    {
        // The pre-backfill state is "every row at whatever it happened to hold, mostly 0" - not
        // something worth reconstructing, and restoring it would just bring the bug back.
        $this->throwIrreversibleMigrationException();
    }
}
