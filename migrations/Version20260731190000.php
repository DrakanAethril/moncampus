<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops InternshipTutorLink's four free-text tutor_* columns and makes tutor_id mandatory: an
 * alternance's tutor is now a real App\Entity\User from the moment the link is created (see
 * App\Service\InternshipTutorProvisioningService), so the address staff type is that account's
 * User::$contactEmail - the single address anything in the platform mails a tutor at.
 *
 * Assumes no pre-existing links, which is why nothing is backfilled here: production carries none
 * (confirmed before this was written), and any local row without a tutor_id has to be given one -
 * or deleted - before this runs, since the NOT NULL would otherwise be rejected outright.
 */
final class Version20260731190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make internship_tutor_link.tutor_id mandatory and drop the free-text tutor_* columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link
            DROP tutor_first_name,
            DROP tutor_last_name,
            DROP tutor_email,
            DROP tutor_phone');
        $this->addSql('ALTER TABLE internship_tutor_link CHANGE tutor_id tutor_id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link CHANGE tutor_id tutor_id INT DEFAULT NULL');
        $this->addSql("ALTER TABLE internship_tutor_link
            ADD tutor_first_name VARCHAR(255) DEFAULT '' NOT NULL,
            ADD tutor_last_name VARCHAR(255) DEFAULT '' NOT NULL,
            ADD tutor_email VARCHAR(255) DEFAULT '' NOT NULL,
            ADD tutor_phone VARCHAR(30) DEFAULT '' NOT NULL");
    }
}
