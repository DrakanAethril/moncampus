<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711150615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add visible_in_booklet/visible_in_program to skill and internship_skill_group, now that both are managed at Program level instead of only within the Livret Alternant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill ADD visible_in_booklet TINYINT NOT NULL DEFAULT 1, ADD visible_in_program TINYINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE internship_skill_group ADD visible_in_booklet TINYINT NOT NULL DEFAULT 1, ADD visible_in_program TINYINT NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE skill DROP visible_in_booklet, DROP visible_in_program');
        $this->addSql('ALTER TABLE internship_skill_group DROP visible_in_booklet, DROP visible_in_program');
    }
}
