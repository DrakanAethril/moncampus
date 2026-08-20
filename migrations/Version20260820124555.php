<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What is still owed to a machine the creation wizard started.
 *
 * The wizard answers a redirect while Proxmox is still copying the disk, so nothing of it is left
 * to configure the machine when the clone lands. These three columns are what lets whoever watches
 * the operation next finish the job, exactly once.
 */
final class Version20260820124555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the completion state a wizard-created machine still owes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_operation ADD completion_requested TINYINT DEFAULT 0 NOT NULL, ADD start_after_creation TINYINT DEFAULT 0 NOT NULL, ADD configured_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_operation DROP completion_requested, DROP start_after_creation, DROP configured_at');
    }
}
