<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Optional parent group, so groups form a hierarchy (SIO2 under SIO, SIO under Campus)';
    }

    public function up(Schema $schema): void
    {
        // ON DELETE SET NULL rather than CASCADE: deleting a group must not take the groups under
        // it with it - they become roots again. In practice groups are deactivated, not deleted,
        // and a deactivated parent keeps its children pointing at it.
        $this->addSql('ALTER TABLE `group` ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_6DC044C5727ACA70 FOREIGN KEY (parent_id) REFERENCES `group` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6DC044C5727ACA70 ON `group` (parent_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_6DC044C5727ACA70');
        $this->addSql('DROP INDEX IDX_6DC044C5727ACA70 ON `group`');
        $this->addSql('ALTER TABLE `group` DROP parent_id');
    }
}
