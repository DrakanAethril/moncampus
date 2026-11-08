<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The ménagerie (App\Service\FixedAvatars, 2026-11-08): a hundred ready-made avatars, stored as
 * their identifier.
 *
 * - `user.avatar_fixed` is the one a person picked on « Mon profil » (`avatar_choice` = 'fixed'
 *   shows it);
 * - `quiz_live_participant.guest_fixed_avatar` is a guest's, the only avatars a guest is offered
 *   now. The guests who joined before keep their drawn `guest_avatar`.
 */
final class Version20261108105727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ménagerie avatars: user.avatar_fixed, quiz_live_participant.guest_fixed_avatar';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_live_participant ADD guest_fixed_avatar VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD avatar_fixed VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_live_participant DROP guest_fixed_avatar');
        $this->addSql('ALTER TABLE `user` DROP avatar_fixed');
    }
}
