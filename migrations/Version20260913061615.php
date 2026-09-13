<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The jobboard is ordered on `first_seen_at` rather than on the publication date, and `sort_date`
 * goes with the order it existed for.
 *
 * `sort_date` was never data: it was `published_at ?? '1000-01-01'`, rewritten on every save so an
 * undated offer had a stable place at the far end of a date-descending list. Nothing reads it any
 * more, and a derived column nobody reads is schema drift - which is why down() can rebuild it
 * exactly, from the column it was always derived from.
 */
final class Version20260913061615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jobboard: order on first_seen_at, drop the derived sort_date column';
    }

    public function up(Schema $schema): void
    {
        // The listing index moves with the ORDER BY it serves. The standalone (track_id,
        // first_seen_at) index goes: the « Repérée depuis » filter always runs inside the reading
        // perimeter, so it is now a prefix of the listing index.
        $this->addSql('DROP INDEX idx_jobboard_offer_first_seen ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_listing ON jobboard_offer');
        $this->addSql('ALTER TABLE jobboard_offer DROP sort_date');
        $this->addSql('CREATE INDEX idx_jobboard_offer_listing ON jobboard_offer (track_id, closed_at, first_seen_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_jobboard_offer_listing ON jobboard_offer');

        // Added nullable, backfilled, then closed: a NOT NULL column added in one statement to a
        // table that already has rows is filled by the engine's idea of an empty date, and a
        // DEFAULT would outlive its ALTER and read as drift on the next schema:validate.
        $this->addSql('ALTER TABLE jobboard_offer ADD sort_date DATE DEFAULT NULL');
        $this->addSql("UPDATE jobboard_offer SET sort_date = COALESCE(published_at, '1000-01-01')");
        $this->addSql('ALTER TABLE jobboard_offer CHANGE sort_date sort_date DATE NOT NULL');

        $this->addSql('CREATE INDEX idx_jobboard_offer_first_seen ON jobboard_offer (track_id, first_seen_at)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_listing ON jobboard_offer (track_id, closed_at, sort_date, id)');
    }
}
