<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The fourth ldap_manage_* queue: the gestures that bear on an account which already exists -
 * deactivate, reactivate, rename - as opposed to ldap_manage_user, which creates one.
 *
 * Its state/pid/started_at/ended_at/log columns are the contract the three others already carry,
 * copied unchanged so App\Service\QueueStateFormatter and the consumer script on the domain
 * controller both find what they know. verified_at, verification_note and applied_at are new and
 * belong to this application alone - they answer a question about the script, so the script neither
 * reads nor writes them.
 *
 * No password column, and there will not be one: this is the simplest of the four queues.
 *
 * The schema is owned here, as always; config/init.sql of the Scripts/samba/ldap project gets the
 * copy, without the foreign key - exactly the arrangement ldap_manage_password already has.
 */
final class Version20260824160239 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ldap_manage_account, the queue of deactivate/reactivate/rename requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ldap_manage_account (id INT UNSIGNED AUTO_INCREMENT NOT NULL, action_type VARCHAR(255) NOT NULL, login VARCHAR(255) NOT NULL, new_login VARCHAR(255) DEFAULT NULL, added_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, added_by VARCHAR(255) DEFAULT \'direct\' NOT NULL, state SMALLINT UNSIGNED DEFAULT 0 NOT NULL, pid INT UNSIGNED DEFAULT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, log LONGTEXT DEFAULT NULL, verified_at DATETIME DEFAULT NULL, verification_note VARCHAR(255) DEFAULT NULL, applied_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_BDF2ED8CA76ED395 (user_id), INDEX idx_ldap_manage_account_user_state (user_id, state), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ldap_manage_account ADD CONSTRAINT FK_BDF2ED8CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ldap_manage_account DROP FOREIGN KEY FK_BDF2ED8CA76ED395');
        $this->addSql('DROP TABLE ldap_manage_account');
    }
}
