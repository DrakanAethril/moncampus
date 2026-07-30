<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Cette séance contient une évaluation" + its nature, on the three rungs of the séance copy chain:
 * the library template, the frozen instance it produces, and the progression's own copy.
 *
 * One nullable column rather than a boolean plus a nature - null simply means "ordinary séance", so
 * "flagged but with no nature" is a state the schema cannot hold. Nothing to backfill: every
 * existing séance is exactly that ordinary case.
 */
final class Version20260730210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Séances carry an optional evaluation nature (D/F/S), read by the progression';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance_template ADD evaluation_nature VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE seance_instance ADD evaluation_nature VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE progression_seance ADD evaluation_nature VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seance_template DROP evaluation_nature');
        $this->addSql('ALTER TABLE seance_instance DROP evaluation_nature');
        $this->addSql('ALTER TABLE progression_seance DROP evaluation_nature');
    }
}
