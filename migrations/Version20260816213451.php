<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deferred object deletion (design/validated/object-deletion.md).
 *
 * One row per object whose bytes are due to leave the bucket. Nothing in the application removes
 * bytes any more: App\Service\ObjectStore writes here instead, and App\Command\PurgeUploadsCommand
 * is the only thing that reads the table and actually deletes - which is what gives the file library
 * a corbeille and what makes a mistaken delete recoverable for the retention window of its origin.
 *
 * `storage_key` is UNIQUE and holds the **full** key, environment prefix included, because that is
 * what the purge hands to S3. `purged_at` stays null until the bytes are genuinely gone: a row
 * stamped early would turn a permission problem into a permanent, invisible leak.
 *
 * `attempts` carries no DEFAULT on purpose - the value belongs to the PHP constructor, a column
 * default only serving the ALTER and then showing up as drift in doctrine:schema:validate.
 */
final class Version20260816213451 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression différée : table deleted_object';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deleted_object (id INT UNSIGNED AUTO_INCREMENT NOT NULL, storage_key VARCHAR(255) NOT NULL, deleted_at DATETIME NOT NULL, origin VARCHAR(64) NOT NULL, purged_at DATETIME DEFAULT NULL, attempts INT UNSIGNED NOT NULL, last_error VARCHAR(255) DEFAULT NULL, deleted_by_id INT DEFAULT NULL, INDEX IDX_2AC8D712C76F1F52 (deleted_by_id), INDEX idx_deleted_object_pending (purged_at, deleted_at), UNIQUE INDEX uniq_deleted_object_storage_key (storage_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deleted_object ADD CONSTRAINT FK_2AC8D712C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deleted_object DROP FOREIGN KEY FK_2AC8D712C76F1F52');
        $this->addSql('DROP TABLE deleted_object');
    }
}
