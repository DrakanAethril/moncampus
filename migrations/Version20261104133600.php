<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `quiz_tournaments`, **off for every role**.
 *
 * Data only. The quiz tournaments ship visible to the administrator alone - who has everything by
 * construction and therefore no column - and Gestion > Fonctionnalités opens them to the students
 * and teachers once tried (design/validated/quiz-tournament.md §2). Written rather than left absent
 * for the reason Version20260916151000 gives: an absent pair would fall back on today's catalogue.
 */
final class Version20261104133600 extends AbstractMigration
{
    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    public function getDescription(): string
    {
        return 'Seed the quiz_tournaments feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('quiz_tournaments', '".$role."', 0)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'quiz_tournaments'");
    }
}
