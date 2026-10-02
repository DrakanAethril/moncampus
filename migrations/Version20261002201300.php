<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `online_courses`, **off for every role**.
 *
 * Data only. Writing and publishing courses on a public page ships visible to the administrator
 * alone - who has everything by construction and therefore no column - and is opened to the
 * teachers from Gestion > Fonctionnalités once tried (design/validated/cours-en-ligne.md §3).
 * Written rather than left absent for the reason Version20260916151000 gives: an absent pair would
 * fall back on today's catalogue.
 */
final class Version20261002201300 extends AbstractMigration
{
    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    public function getDescription(): string
    {
        return 'Seed the online_courses feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('online_courses', '".$role."', 0)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'online_courses'");
    }
}
