<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260804164305 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'origine d\'un alias Courrier école (generated/login/manual), qui décide des règles de forme et de son caractère administrable.';
    }

    /**
     * The origin carries two rules at once: an alias entered by hand must contain a dot
     * (`something.something`) and it is the only one administrable from the application.
     *
     * The dot is not cosmetic. Reception being catch-all, creating an alias amounts to manufacturing
     * a sending identity on the school's domain: without this rule, `comptabilite@` or `direction@`
     * would be indistinguishable from an official address for the company receiving them, when they
     * in fact point to a student's mailbox.
     *
     * The login alias (`croux`) is the only exception - it never has a dot because it takes the
     * directory's username and is typed by nobody.
     *
     * Column added nullable then filled before being made mandatory: the development database
     * already carries the migrated aliases, and an ADD ... NOT NULL with no default would fill them
     * with an empty string.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias ADD origin VARCHAR(20) DEFAULT NULL');

        // Provisioning only sets two origins: the student's main address is the one composed from
        // their civil status, every other one is their login.
        $this->addSql('UPDATE email_alias a JOIN `user` u ON u.primary_alias_id = a.id SET a.origin = \'generated\'');
        $this->addSql('UPDATE email_alias SET origin = \'login\' WHERE origin IS NULL');

        $this->addSql('ALTER TABLE email_alias MODIFY origin VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP origin');
    }
}
