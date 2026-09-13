<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `jobboard`, and gains it **off for every single role**.
 *
 * Data only: nothing in the schema changes. The rows are written rather than left absent because
 * an absent pair falls back on App\Enum\Feature::defaultForRoles(), and the matrix screen shows a
 * row only for the pairs it holds - a feature nobody can see is a feature nobody can switch on.
 *
 * Off everywhere is the decision, not an oversight: the offers arrive from an outside collecting
 * agent, and an establishment decides for itself when - and to whom - it starts showing them.
 * `ROLE_ADMIN` has no column by construction and has everything anyway, which is what makes the
 * board readable on the day of the deployment.
 *
 * Written out rather than derived from the enum, like every other seeding migration here: a
 * migration must keep meaning what it meant, even if the catalogue's defaults move afterwards.
 */
final class Version20260912210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the jobboard feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach ([
            'ROLE_STUDENT',
            'ROLE_TEACHER',
            'ROLE_STAFF',
            'ROLE_STAFF-LEAD',
            'ROLE_TUTOR',
            'ROLE_SUPPORT-TECH',
            'ROLE_ECO',
            'ROLE_EXTERNAL',
        ] as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('jobboard', '".$role."', 0)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'jobboard'");
    }
}
