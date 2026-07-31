<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Switches every existing account to the light theme, completing what Version20260731170000
 * started for new accounts only.
 *
 * Deliberately destructive, and asked for explicitly: the column is NOT NULL with a default and
 * keeps no trace of who actually picked dark versus who merely inherited the old default, so this
 * overwrites real choices along with inherited ones. Anyone who wants dark back sets it again from
 * their profile - a one-click, per-account fix, unlike leaving the whole estate on a theme nobody
 * chose.
 *
 * Separate from Version20260731170000 rather than folded into it: that one has already run in dev
 * and is recorded as executed, so editing it would quietly skip this statement there while
 * applying it in production.
 */
final class Version20260731180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch every existing account to the light theme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE `user` SET theme_preference = 'light' WHERE theme_preference <> 'light'");
    }

    public function down(Schema $schema): void
    {
        // Which accounts were on dark before this ran is exactly the information this migration
        // destroys - there is nothing to restore them from.
        $this->throwIrreversibleMigrationException();
    }
}
