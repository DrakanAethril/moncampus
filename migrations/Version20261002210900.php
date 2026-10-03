<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `learning_paths`, **off for every role**.
 *
 * Data only. Following a learning path ships visible to the administrator alone and is opened -
 * to the students, typically - from Gestion > Fonctionnalités once a path exists to follow
 * (design/validated/cours-en-ligne.md §3). Written rather than left absent for the reason
 * Version20260916151000 gives: an absent pair would fall back on today's catalogue.
 */
final class Version20261002210900 extends AbstractMigration
{
    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    public function getDescription(): string
    {
        return 'Seed the learning_paths feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('learning_paths', '".$role."', 0)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'learning_paths'");
    }
}
