<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User::$testUser - the account-side counterpart of Program::$testProgram.
 *
 * NOT NULL DEFAULT 0: an account is a real one unless someone says otherwise, so every existing
 * row is already right and there is nothing to backfill.
 */
final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.test_user - a test account only ever sees test programs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD test_user TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP test_user');
    }
}
