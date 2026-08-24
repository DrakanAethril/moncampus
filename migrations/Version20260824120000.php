<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clears the passwords ldap_manage_password has accumulated since the queue existed.
 *
 * The column is not going away: the queue is asynchronous, so a password the user chose on their
 * profile has to survive in the row until the external consumer script picks it up. What changed
 * on 2026-08-24 is everything after that moment - the script now clears the column as its last
 * act, and Annuaire > Mots de passe no longer has a "Voir" button, so nothing in this application
 * decrypts one any more. This migration deals with the past, which those two changes cannot reach:
 * every row processed before them still holds the password that was applied, readable by anyone
 * holding AES_KEY and the database.
 *
 * Rows still in state 0 are deliberately spared. They are the only ones whose password is still
 * needed: it has not been applied yet, and wiping it would hand the user a random password nobody
 * knows instead of the one they typed. Everything else - claimed, succeeded, failed - has already
 * given the script what it needed.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wipe stored passwords from ldap_manage_password, except rows not yet processed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE ldap_manage_password SET password = NULL WHERE state <> 0');
    }

    public function down(Schema $schema): void
    {
        // Nothing to restore, and nothing that should be: the whole point of this migration is
        // that those passwords stop existing.
        $this->throwIrreversibleMigrationException();
    }
}
