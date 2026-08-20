<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a deployment pass last took an item in hand, so passes can share their turns fairly.
 *
 * Nullable, and null means "never attempted" - which sorts first, so the machines of an existing
 * batch that has not finished are picked up before ones already tried rather than after them.
 */
final class Version20260820124139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds vm_batch_item.last_attempt_at: whose turn a deployment pass takes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch_item ADD last_attempt_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vm_batch_item DROP last_attempt_at');
    }
}
