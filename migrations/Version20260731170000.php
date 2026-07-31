<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Flips the column default behind User::$themePreference from 'dark' to 'light'.
 *
 * Default only - existing rows are deliberately left untouched. The column is NOT NULL with a
 * default, so it holds no trace of whether a given value was picked by its owner or just inherited
 * from the old default; rewriting it would silently override the choice of everyone who did pick
 * dark. New accounts start light, everybody else keeps what they have.
 */
final class Version20260731170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Default user.theme_preference to 'light' for new accounts";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` CHANGE theme_preference theme_preference VARCHAR(5) DEFAULT 'light' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` CHANGE theme_preference theme_preference VARCHAR(5) DEFAULT 'dark' NOT NULL");
    }
}
