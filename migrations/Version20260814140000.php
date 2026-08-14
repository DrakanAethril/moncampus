<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Which creneaux a progression sequence may be placed on: matiere scope, class composition, and the one-seance-per-week limit';
    }

    public function up(Schema $schema): void
    {
        // The defaults ARE the behaviour every existing row already had: a progression only ever
        // saw its own matière's créneaux, took whichever it found, and filled every week it could.
        // So no data migration is needed - the columns describe the status quo, and the screen is
        // what lets a teacher depart from it.
        //
        // They are dropped again immediately, because the entity does not declare them: a column
        // DEFAULT left in place is drift, and doctrine:schema:validate reports it on every run
        // afterwards. It is only needed for the instant the column lands on a table that already
        // has rows - what a new row reads is the PHP property default.
        $this->addSql("ALTER TABLE progression_sequence ADD slot_composition VARCHAR(20) DEFAULT 'all' NOT NULL, ADD slot_topic_scope VARCHAR(20) DEFAULT 'own' NOT NULL, ADD slot_topic_id INT DEFAULT NULL, ADD one_seance_per_week TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql('ALTER TABLE progression_sequence ALTER slot_composition DROP DEFAULT');
        $this->addSql('ALTER TABLE progression_sequence ALTER slot_topic_scope DROP DEFAULT');
        $this->addSql('ALTER TABLE progression_sequence ALTER one_seance_per_week DROP DEFAULT');
        // Doctrine's own generated names, so that schema:validate has nothing to rename.
        $this->addSql('ALTER TABLE progression_sequence ADD CONSTRAINT FK_15574D88CE0041AE FOREIGN KEY (slot_topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_15574D88CE0041AE ON progression_sequence (slot_topic_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_sequence DROP FOREIGN KEY FK_15574D88CE0041AE');
        $this->addSql('DROP INDEX IDX_15574D88CE0041AE ON progression_sequence');
        $this->addSql('ALTER TABLE progression_sequence DROP slot_composition, DROP slot_topic_scope, DROP slot_topic_id, DROP one_seance_per_week');
    }
}
