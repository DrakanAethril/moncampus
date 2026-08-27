<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The campus game's filière moves onto the option.
 *
 * `program.game_track` was the wrong home and it took a real class to show it: BTS SIO holds SLAM
 * and SISR side by side in one formation, and which of the two a student belongs to is their
 * **option**. A single value stamped on the Program cannot say that, so two students of one class
 * could not read different level wording nor draw from different figure catalogues.
 *
 * The column on `program` is deliberately **kept** rather than migrated away: it is now the fallback
 * for a formation whose whole class is one filière - Comptabilité, Management commercial - which
 * splits into no option at all and has nothing else to say so with. Nothing is copied either way;
 * `game_track` is null everywhere in production, the game having never been switched on.
 */
final class Version20260827220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campus game: the filière is carried by the option, the program keeping it as a fallback';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `option` ADD game_track VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `option` DROP game_track');
    }
}
