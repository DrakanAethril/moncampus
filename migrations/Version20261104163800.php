<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The students' cell leaves the serious games matrix (design/validated/quiz-tournament.md §14): a
 * game is opened to students by its targeting alone, so the ROLE_STUDENT rows go. **Nothing is
 * carried over** (the user's decision of 2026-11-04): every game starts with an empty targeting,
 * closed to every student, until an administrator targets it in Paramètres › Serious games.
 *
 * `down()` gives nothing back: the cells removed held no information the targeting needs, and
 * restoring them lit would open games nobody reopened.
 */
final class Version20261104163800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serious games: the students\' matrix cell is removed, every game starts closed to students';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM serious_game_role_setting WHERE role = 'ROLE_STUDENT'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
