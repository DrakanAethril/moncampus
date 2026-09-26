<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sign-up lists store their number of registrations instead of counting them on every screen and
 * every mobile answer that shows it - the agenda feed counted once per event.
 *
 * Filled from the existing registrations here; App\Service\SignupListRegistrar moves it from then
 * on, and `app:counters:recompute` checks it.
 */
final class Version20260926073139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the registration count on signup_list';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup_list ADD registration_count INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE signup_list l
            JOIN (SELECT signup_list_id, COUNT(*) AS total FROM signup_list_registration GROUP BY signup_list_id) r
              ON r.signup_list_id = l.id
            SET l.registration_count = r.total');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE signup_list DROP registration_count');
    }
}
