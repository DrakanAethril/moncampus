<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clears the passwords ldap_manage_user has accumulated since account creation existed - the
 * counterpart of Version20260824120000, which did the same for ldap_manage_password.
 *
 * This one takes every row, with no exception, and the asymmetry is the point. In
 * ldap_manage_password the column has a job: it carries a password the user chose across the
 * asynchronous gap until the consumer script picks the row up, so rows still waiting keep it.
 * Here it never had one - create_user.sh's password is invented by the consumer script itself, and
 * this application neither writes nor reads the column. It was written back purely as a record,
 * which means every account ever created through this app has been sitting here holding its
 * initial password, readable by anyone with AES_KEY and the database.
 *
 * The script stops writing it as of the same day; this deals with the history that change cannot
 * reach.
 */
final class Version20260824140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wipe stored passwords from ldap_manage_user - the column was never read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE ldap_manage_user SET password = NULL');
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore, and nothing that should be: the whole point of this migration is
        // that those passwords stop existing.
        $this->throwIrreversibleMigrationException();
    }
}
