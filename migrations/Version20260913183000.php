<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The blacklist: a site the establishment does not want on the board.
 *
 * Two columns, and they are two halves of one gesture. `jobboard_source.blacklisted_at` says which
 * sites are out and since when - on the row itself, because the domains are there and they are what
 * a later offer is resolved against. `jobboard_batch.blocked_count` says what that costs each
 * deposit, and it is separate from `rejected_count` on purpose: a refusal is a defect of the offer,
 * a block is a decision of the establishment, and adding them up would read as the veille having
 * started producing garbage.
 *
 * The offers of a site being blacklisted are deleted by the screen that does it, not here: nothing
 * is blacklisted yet on the day this runs.
 */
final class Version20260913183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Blacklist a jobboard source, and count the offers it drops';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_source ADD blacklisted_at DATETIME DEFAULT NULL');
        // The DEFAULT only serves the length of the ALTER: the column the entity describes carries
        // none, and leaving one behind is a schema drift `doctrine:schema:validate` would catch.
        $this->addSql('ALTER TABLE jobboard_batch ADD blocked_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE jobboard_batch ALTER blocked_count DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_source DROP blacklisted_at');
        $this->addSql('ALTER TABLE jobboard_batch DROP blocked_count');
    }
}
