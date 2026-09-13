<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jobboard: the offers a pass reported as gone, counted on the pass itself.
 *
 * The three tallies already stored say what a batch brought; this one says what it took away, so
 * the Configuration > Jobboard > Historique screen can read a day of veille in one line.
 *
 * The `DEFAULT 0` serves the ALTER alone and is dropped right after - the PHP property carries the
 * default, and a DEFAULT left in the schema makes `doctrine:schema:validate` diverge on every run.
 */
final class Version20260913040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jobboard: compteur des offres retirées sur un lot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_batch ADD closed_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE jobboard_batch ALTER closed_count DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jobboard_batch DROP closed_count');
    }
}
