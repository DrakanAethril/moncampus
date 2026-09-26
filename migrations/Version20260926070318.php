<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `equipment` (Gestion > Matériel), **on for staff, staff-lead and the
 * technical support**, off for everyone else.
 *
 * Data only; the tables are Version20260926070317's business. Written out rather than derived from
 * the enum, like its neighbours: a migration must keep meaning what it meant.
 */
final class Version20260926070318 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the equipment feature in the role matrix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_STUDENT', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_TEACHER', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_STAFF', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_STAFF-LEAD', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_TUTOR', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_SUPPORT-TECH', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_ECO', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('equipment', 'ROLE_EXTERNAL', 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'equipment'");
    }
}
