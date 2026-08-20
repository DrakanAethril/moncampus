<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MonCampus creates its own account on a machine rather than borrowing the image's default one.
 *
 * The column arrived a few hours earlier defaulting to `root`, on the assumption that a cloud image
 * would offer a usable default user to name instead. It need not: a hand-built template may create
 * none at all, and root is precisely the account cloud-init neuters. The account is now handed to
 * cloud-init as `ciuser`, so naming it is what brings it into existence.
 *
 * The rows still holding `root` are moved with the default. That is not overwriting a decision:
 * the setting has never reached production, so every existing row carries a value nobody chose.
 */
final class Version20260820190446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Defaults guest_login_user to the account MonCampus creates for itself.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_host CHANGE guest_login_user guest_login_user VARCHAR(32) DEFAULT \'moncampus\' NOT NULL');
        // The value nobody chose moves with the default - see the class docblock. A host genuinely
        // wanting root is set back to it on its own screen.
        $this->addSql('UPDATE proxmox_host SET guest_login_user = \'moncampus\' WHERE guest_login_user = \'root\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE proxmox_host CHANGE guest_login_user guest_login_user VARCHAR(32) DEFAULT \'root\' NOT NULL');
    }
}
