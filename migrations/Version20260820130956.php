<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The installation log of one machine: what was done to it and what came of it, in one column.
 *
 * One column rather than a table because it is a narrative - read by a human, in order, about one
 * machine, when that machine will not let them in. See App\Entity\VmBatchItem.
 */
final class Version20260820130956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds vm_batch_item.install_log: the story of one machine s installation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch_item ADD install_log LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch_item DROP install_log');
    }
}
