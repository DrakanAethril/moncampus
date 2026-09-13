<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Jobboard's filière moves down one level of the structure: it was a Section, it becomes a
 * Track.
 *
 * A Section is « l'enseignement supérieur » - the order of teaching, not a course of study - so a
 * key scoped to one filed the offers of every formation under it into a single board. A filière is
 * « BTS SIO », which is a Track. Four tables carry it and all four move together: the offers, the
 * ingestion keys, the batches and the cursors.
 *
 * **The column is renamed, then remapped.** `CHANGE section_id track_id` keeps the values it held,
 * which are section ids, so the UPDATE that follows is not optional: it is what turns them into
 * track ids. The foreign key is only added after that, on values that are already tracks.
 *
 * A section holding jobboard rows but no track cannot be mapped, and `preUp()` refuses the
 * migration rather than guessing or deleting: an offer carries a `first_seen_at` that no site can
 * give back. The fix is to give that section a track, or to delete its jobboard rows by hand.
 */
final class Version20260913051515 extends AbstractMigration
{
    /** The tables carrying the filière, and the lowest track id of a section is the one they land on. */
    private const array TABLES = ['jobboard_offer', 'jobboard_token', 'jobboard_batch', 'jobboard_cursor'];

    public function getDescription(): string
    {
        return "Le jobboard range ses offres par filière (Track) et non plus par section";
    }

    public function preUp(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $orphans = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.$table.' x WHERE NOT EXISTS (SELECT 1 FROM track t WHERE t.section_id = x.section_id)'
            );

            $this->abortIf(
                $orphans > 0,
                \sprintf(
                    '%d ligne(s) de %s sont rattachées à une section sans aucune filière : donnez-lui une filière, ou supprimez ces lignes, avant de rejouer cette migration.',
                    $orphans,
                    $table,
                ),
            );
        }
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_batch DROP FOREIGN KEY `FK_538AD59AD823E37A`');
        $this->addSql('DROP INDEX IDX_538AD59AD823E37A ON jobboard_batch');
        $this->addSql('ALTER TABLE jobboard_batch CHANGE section_id track_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_cursor DROP FOREIGN KEY `FK_54B0879ED823E37A`');
        $this->addSql('DROP INDEX IDX_54B0879ED823E37A ON jobboard_cursor');
        $this->addSql('DROP INDEX uniq_jobboard_cursor_scope ON jobboard_cursor');
        $this->addSql('ALTER TABLE jobboard_cursor CHANGE section_id track_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_offer DROP FOREIGN KEY `FK_82570070D823E37A`');
        $this->addSql('DROP INDEX IDX_82570070D823E37A ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_contract ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_departement ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_first_seen ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_listing ON jobboard_offer');
        $this->addSql('DROP INDEX uniq_jobboard_offer_identity ON jobboard_offer');
        $this->addSql('ALTER TABLE jobboard_offer CHANGE section_id track_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_token DROP FOREIGN KEY `FK_F4B62675D823E37A`');
        $this->addSql('DROP INDEX IDX_F4B62675D823E37A ON jobboard_token');
        $this->addSql('ALTER TABLE jobboard_token CHANGE section_id track_id INT NOT NULL');

        // The column now holds section ids under a track name. One filière per section, the lowest
        // id when a section carries several: preUp() has already refused the case where there is
        // none. Two sections never map to the same track, so no UNIQUE index below can collide.
        foreach (self::TABLES as $table) {
            $this->addSql('UPDATE '.$table.' x SET x.track_id = (SELECT MIN(t.id) FROM track t WHERE t.section_id = x.track_id)');
        }

        $this->addSql('ALTER TABLE jobboard_batch ADD CONSTRAINT FK_538AD59A5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_538AD59A5ED23C43 ON jobboard_batch (track_id)');
        $this->addSql('ALTER TABLE jobboard_cursor ADD CONSTRAINT FK_54B0879E5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_54B0879E5ED23C43 ON jobboard_cursor (track_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_cursor_scope ON jobboard_cursor (track_id, source, search)');
        $this->addSql('ALTER TABLE jobboard_offer ADD CONSTRAINT FK_825700705ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_825700705ED23C43 ON jobboard_offer (track_id)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_contract ON jobboard_offer (track_id, contract)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_departement ON jobboard_offer (track_id, departement)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_first_seen ON jobboard_offer (track_id, first_seen_at)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_listing ON jobboard_offer (track_id, closed_at, sort_date, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_offer_identity ON jobboard_offer (track_id, source, source_ref)');
        $this->addSql('ALTER TABLE jobboard_token ADD CONSTRAINT FK_F4B626755ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F4B626755ED23C43 ON jobboard_token (track_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_batch DROP FOREIGN KEY FK_538AD59A5ED23C43');
        $this->addSql('DROP INDEX IDX_538AD59A5ED23C43 ON jobboard_batch');
        $this->addSql('ALTER TABLE jobboard_batch CHANGE track_id section_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_cursor DROP FOREIGN KEY FK_54B0879E5ED23C43');
        $this->addSql('DROP INDEX IDX_54B0879E5ED23C43 ON jobboard_cursor');
        $this->addSql('DROP INDEX uniq_jobboard_cursor_scope ON jobboard_cursor');
        $this->addSql('ALTER TABLE jobboard_cursor CHANGE track_id section_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_offer DROP FOREIGN KEY FK_825700705ED23C43');
        $this->addSql('DROP INDEX IDX_825700705ED23C43 ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_listing ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_first_seen ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_departement ON jobboard_offer');
        $this->addSql('DROP INDEX idx_jobboard_offer_contract ON jobboard_offer');
        $this->addSql('DROP INDEX uniq_jobboard_offer_identity ON jobboard_offer');
        $this->addSql('ALTER TABLE jobboard_offer CHANGE track_id section_id INT NOT NULL');
        $this->addSql('ALTER TABLE jobboard_token DROP FOREIGN KEY FK_F4B626755ED23C43');
        $this->addSql('DROP INDEX IDX_F4B626755ED23C43 ON jobboard_token');
        $this->addSql('ALTER TABLE jobboard_token CHANGE track_id section_id INT NOT NULL');

        // Going back up the hierarchy is exact - a track has one section - so unlike up(), this
        // direction guesses nothing. What it does lose is the distinction between two filières of
        // the same section, which land on the same board again.
        foreach (self::TABLES as $table) {
            $this->addSql('UPDATE '.$table.' x SET x.section_id = (SELECT t.section_id FROM track t WHERE t.id = x.section_id)');
        }

        $this->addSql('ALTER TABLE jobboard_batch ADD CONSTRAINT `FK_538AD59AD823E37A` FOREIGN KEY (section_id) REFERENCES section (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_538AD59AD823E37A ON jobboard_batch (section_id)');
        $this->addSql('ALTER TABLE jobboard_cursor ADD CONSTRAINT `FK_54B0879ED823E37A` FOREIGN KEY (section_id) REFERENCES section (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_54B0879ED823E37A ON jobboard_cursor (section_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_cursor_scope ON jobboard_cursor (section_id, source, search)');
        $this->addSql('ALTER TABLE jobboard_offer ADD CONSTRAINT `FK_82570070D823E37A` FOREIGN KEY (section_id) REFERENCES section (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_82570070D823E37A ON jobboard_offer (section_id)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_listing ON jobboard_offer (section_id, closed_at, sort_date, id)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_first_seen ON jobboard_offer (section_id, first_seen_at)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_departement ON jobboard_offer (section_id, departement)');
        $this->addSql('CREATE INDEX idx_jobboard_offer_contract ON jobboard_offer (section_id, contract)');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobboard_offer_identity ON jobboard_offer (section_id, source, source_ref)');
        $this->addSql('ALTER TABLE jobboard_token ADD CONSTRAINT `FK_F4B62675D823E37A` FOREIGN KEY (section_id) REFERENCES section (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F4B62675D823E37A ON jobboard_token (section_id)');
    }
}
