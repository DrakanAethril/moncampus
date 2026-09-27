<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `ecole_directe` (Outils > École Directe, a read-only prototype), **off for
 * every role**: App\Enum\Feature names no role for it, so the administrator is the only one who sees
 * it on the day it ships.
 *
 * Written out rather than left to the fallback so the matrix shows eight explicit « non » instead of
 * eight gaps - same as every feature before it.
 */
final class Version20260927130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the ecole_directe feature in the role matrix';
    }

    public function up(Schema $schema): void
    {
        foreach (['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'] as $role) {
            $this->addSql('INSERT INTO feature_role_setting (feature, role, enabled) VALUES (\'ecole_directe\', ?, 0)', [$role]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'ecole_directe'");
    }
}
