<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The account MonCampus opens an SSH session with on a host's machines.
 *
 * Defaults to root, which is what it did before the setting existed. A Debian or Ubuntu cloud image
 * needs its own account named instead - root accepts the key there and then runs nothing at all.
 */
final class Version20260820182521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds proxmox_host.guest_login_user: which account to log into machines with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_host ADD guest_login_user VARCHAR(32) DEFAULT \'root\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_host DROP guest_login_user');
    }
}
