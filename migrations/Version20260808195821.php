<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Ignorer" answers one deadline instead of a whole work (design_handoff_travail_a_faire, screen 3c).
 *
 * The "Travail à faire" list reads a work asking for several dated productions on one line per
 * production; setting one aside was setting the work aside, taking the deadlines that follow with
 * it. A dismissal now names the expected production it was taken on, null still meaning the
 * assignment as a whole - a quiz, a listening, a deposit with no production spelled out.
 *
 * Existing rows keep their null and so keep their old meaning: what a student set aside back when
 * the work was one line stays set aside as one.
 *
 * The generated diff also carried an unrelated TINYINT default drift on `assignment`, dropped here
 * as it was in Version20260808090000: it is a mapping/schema mismatch predating this change.
 */
final class Version20260808195821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Travail à faire : « Ignorer » porte sur une échéance et non sur tout le travail';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_assignment_dismissal ON assignment_dismissal');
        $this->addSql('ALTER TABLE assignment_dismissal ADD expected_production_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment_dismissal ADD CONSTRAINT FK_F261691350C6920 FOREIGN KEY (expected_production_id) REFERENCES assignment_expected_production (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_F261691350C6920 ON assignment_dismissal (expected_production_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_assignment_dismissal ON assignment_dismissal (assignment_id, student_id, expected_production_id)');
    }

    public function down(Schema $schema): void
    {
        // A work set aside one deadline at a time cannot be told back in the old shape: the rows
        // naming a production are dropped rather than promoted to a whole-work dismissal, which
        // would set aside deadlines the student never touched.
        $this->addSql('DELETE FROM assignment_dismissal WHERE expected_production_id IS NOT NULL');

        $this->addSql('ALTER TABLE assignment_dismissal DROP FOREIGN KEY FK_F261691350C6920');
        $this->addSql('DROP INDEX IDX_F261691350C6920 ON assignment_dismissal');
        $this->addSql('DROP INDEX uniq_assignment_dismissal ON assignment_dismissal');
        $this->addSql('ALTER TABLE assignment_dismissal DROP expected_production_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_assignment_dismissal ON assignment_dismissal (assignment_id, student_id)');
    }
}
