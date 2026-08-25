<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The wiki leaves the teachers' delivered set (App\Enum\Feature::defaultForRole()).
 *
 * A second per-role exception next to e-CO's, and the second one only. It is not a judgement on the
 * tool: students keep their personal and shared wikis, and a teacher who needs one is given it from
 * their own card in the annuaire rather than by their role.
 *
 * A teacher who is also staff keeps it, because the resolver takes the most permissive of somebody's
 * roles and that rule is not bent for one feature.
 */
final class Version20260825170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Switch the wiki off for ROLE_TEACHER in the feature role matrix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE feature = 'wiki' AND `role` = 'ROLE_TEACHER'");
    }

    /**
     * Back to what the catalogue held before: on for every role. Like the migration that set the
     * real defaults, this cannot restore what an admin may have ticked since - it puts the pair
     * back where the previous migration left it, which is the honest answer.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE feature = 'wiki' AND `role` = 'ROLE_TEACHER'");
    }
}
