<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The emploi du temps gains an iCalendar subscription, and an account needs a secret to carry in
 * the URL: a calendar client fetches the .ics from Google's or Apple's servers, with no cookie and
 * no account of its own.
 *
 * Nullable and left empty on every existing row on purpose - the token is minted the first time
 * somebody is actually shown a subscription link, so the day this ships nobody has one and nothing
 * is exposed. The UNIQUE index is what makes the column an identity rather than a field.
 */
final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Abonnement iCal à l'emploi du temps : User.calendar_token, vide partout au départ";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD calendar_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_calendar_token ON `user` (calendar_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_calendar_token ON `user`');
        $this->addSql('ALTER TABLE `user` DROP calendar_token');
    }
}
