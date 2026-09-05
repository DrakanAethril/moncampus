<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `class_list_exports`, the feature behind the « Exporter » button of the two
 * class lists (émargement PDF and CSV).
 *
 * Data only: nothing in the schema changes. Rows are written rather than left absent because an
 * absent pair falls back on App\Enum\Feature::defaultForRoles(), which is role-blind - and the
 * decision here is precisely a role one: the two staff roles get it, nobody else does.
 * `ROLE_ADMIN` has no column by construction and has everything anyway.
 *
 * Written out rather than derived from the enum, like every other seeding migration here: a
 * migration must keep meaning what it meant, even if the catalogue's defaults move afterwards.
 */
final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the class_list_exports feature in the role matrix, on for staff only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_STAFF', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_STAFF-LEAD', 1)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_STUDENT', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_TEACHER', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_TUTOR', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_SUPPORT-TECH', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_ECO', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('class_list_exports', 'ROLE_EXTERNAL', 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'class_list_exports'");
    }
}
