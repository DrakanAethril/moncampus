<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

// An e-CO course's time allowance: the countdown of the Ordre libre and Course au score modes
// (e-CO handoff, screen 2b). Null in Ordre imposé, which is ranked on time.
final class Version20260807065612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "e-CO: a course's time allowance";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_course ADD time_limit_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eco_course DROP time_limit_minutes');
    }
}
