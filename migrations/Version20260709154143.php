<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709154143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace User.displayName (a single LDAP-synced string) with separate firstname/lastname columns, computed back into a display name at read time - see App\Entity\User::getDisplayName().';
    }

    public function up(Schema $schema): void
    {
        // Reusing the display_name column as firstname (rather than dropping it outright) is a
        // deliberate no-op-looking choice: for users unaffected by the cn=login bug this fix
        // addresses, it carries their existing "Firstname Lastname" string forward as a
        // still-reasonable-looking value (just not yet split into two columns) instead of
        // blanking it out - LdapUserMapper corrects it into proper firstname/lastname on their
        // very next login either way, same as it always overwrites these LDAP-owned fields.
        $this->addSql(<<<'SQL'
            ALTER TABLE
              user
            ADD
              lastname VARCHAR(255) DEFAULT NULL,
            CHANGE
              display_name firstname VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD display_name VARCHAR(255) DEFAULT NULL, DROP firstname, DROP lastname');
    }
}
