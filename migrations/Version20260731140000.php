<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Alternance de test" - InternshipTutorLink::$testAlternance plus the entreprise-side flag it
 * propagates to, Enterprise::$testEnterprise. Both are the UFA counterpart of the existing
 * Program::$testProgram / User::$testUser pair.
 *
 * NOT NULL DEFAULT 0 like those two: an alternance (and an employer) is a real one unless someone
 * ticks the box, so every existing row is already right and nothing needs backfilling.
 */
final class Version20260731140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add internship_tutor_link.test_alternance and enterprise.test_enterprise';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link ADD test_alternance TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE enterprise ADD test_enterprise TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE internship_tutor_link DROP test_alternance');
        $this->addSql('ALTER TABLE enterprise DROP test_enterprise');
    }
}
