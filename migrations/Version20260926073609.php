<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every account stores what its file library weighs - the live files' sizes - instead of summing
 * them on every library screen. Filled from the existing files here; App\Service\FileLibraryUsageCounter
 * moves it from then on, and `app:counters:recompute` checks it.
 */
final class Version20260926073609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the file library usage on user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD file_library_used_bytes BIGINT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE `user` u
            JOIN (SELECT owner_id, SUM(size_bytes) AS total FROM file_library_node WHERE type = 'file' AND deleted_at IS NULL GROUP BY owner_id) n
              ON n.owner_id = u.id
            SET u.file_library_used_bytes = COALESCE(n.total, 0)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP file_library_used_bytes');
    }
}
