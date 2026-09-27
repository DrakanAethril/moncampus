<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `claude_connector` (the Claude connector, /mcp), **off for every role**:
 * App\Enum\Feature names no role for it, so the administrator is the only one who can connect on the
 * day it ships, and the establishment lights it role by role from Gestion > Fonctionnalités.
 *
 * Written out rather than left to the fallback so the matrix shows eight explicit « non » instead of
 * eight gaps - same as every feature before it.
 */
final class Version20260927100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the claude_connector feature in the role matrix';
    }

    public function up(Schema $schema): void
    {
        foreach (['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'] as $role) {
            $this->addSql('INSERT INTO feature_role_setting (feature, role, enabled) VALUES (\'claude_connector\', ?, 0)', [$role]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'claude_connector'");
    }
}
