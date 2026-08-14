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
        $this->addSql("ALTER TABLE progression_sequence ADD slot_composition VARCHAR(20) DEFAULT 'all' NOT NULL, ADD slot_topic_scope VARCHAR(20) DEFAULT 'own' NOT NULL, ADD slot_topic_id INT DEFAULT NULL, ADD one_seance_per_week TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql('ALTER TABLE progression_sequence ADD CONSTRAINT FK_progression_sequence_slot_topic FOREIGN KEY (slot_topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_progression_sequence_slot_topic ON progression_sequence (slot_topic_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_sequence DROP FOREIGN KEY FK_progression_sequence_slot_topic');
        $this->addSql('DROP INDEX IDX_progression_sequence_slot_topic ON progression_sequence');
        $this->addSql('ALTER TABLE progression_sequence DROP slot_composition, DROP slot_topic_scope, DROP slot_topic_id, DROP one_seance_per_week');
    }
}
