<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `dossiers`, **off for every role**.
 *
 * Data only; the tables are Version20260916150000's business. The rows are written rather than left
 * absent for the same reason as every other seeding migration here: an absent pair falls back on
 * App\Enum\Feature::defaultForRoles(), which answers for the catalogue as it stands *today*, and a
 * migration must keep meaning what it meant. Eight zeroes are also the decision itself - the tool
 * ships switched off and the administrator, who has everything by construction and therefore no
 * column, is the only one who sees it on the day it lands.
 *
 * Written out rather than derived from the enum, like its neighbours.
 */
final class Version20260916151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the dossiers feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_STUDENT', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_TEACHER', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_STAFF', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_STAFF-LEAD', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_TUTOR', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_SUPPORT-TECH', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_ECO', 0)");
        $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('dossiers', 'ROLE_EXTERNAL', 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'dossiers'");
    }
}
