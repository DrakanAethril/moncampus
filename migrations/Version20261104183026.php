<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The avatar leaves the serious games for « Mon profil » (design/validated/quiz-tournament.md §16):
 * what stands for a person becomes their explicit choice, `user.avatar_choice` (`photo` | `drawn`,
 * null = the initials).
 *
 * The rows are filled so that **nobody's face changes** on the day it ships, which is the rule
 * App\Service\UserAvatars applied until now: a photo first; else, for a student - by LDAP role or by
 * a manual group - the drawing they made in the serious games; else the initials. An adult's
 * drawing was never shown outside the games, so it stays unchosen. Nothing is deleted: the photo
 * and the drawing both stay where they are.
 */
final class Version20261104183026 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User: avatar_choice (photo | drawn | null), filled from today\'s display';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD avatar_choice VARCHAR(10) DEFAULT NULL');
        $this->addSql("UPDATE `user` SET avatar_choice = 'photo' WHERE avatar_key IS NOT NULL");
        $this->addSql(<<<'SQL'
            UPDATE `user` u
            INNER JOIN serious_game_avatar a ON a.user_id = u.id
            SET u.avatar_choice = 'drawn'
            WHERE u.avatar_key IS NULL
              AND (u.roles LIKE '%"ROLE_STUDENT"%'
                   OR EXISTS (SELECT 1 FROM user_group ug INNER JOIN `group` g ON g.id = ug.group_id
                              WHERE ug.user_id = u.id AND g.role = 'ROLE_STUDENT'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP avatar_choice');
    }
}
