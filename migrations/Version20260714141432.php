<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repairs environments where Version20260714073140 was executed - and recorded as such in
 * doctrine_migration_versions - back when its SQL still read "ADD ordre INT NOT NULL" (French),
 * before commit 3aa7cfb edited that same already-applied migration file's SQL in place to
 * "ADD `order` INT NOT NULL" instead of adding a proper follow-up migration. Editing an
 * already-executed migration's SQL never gets re-run anywhere it already executed - Doctrine only
 * tracks the version identifier, not its SQL content - so any environment that ran the old
 * migration before the edit (production) is stuck with the stale `ordre` column and never got
 * `order`, causing "Unknown column 's0_.order'" on every SequenceTemplate query. Environments that
 * only ever ran the migration after the edit (local dev) already have `order` and no `ordre`.
 *
 * Written to be a no-op wherever the column is already correctly named `order`, so it's safe to
 * run on every environment regardless of which state it's in.
 */
final class Version20260714141432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair sequence_template.ordre -> order where Version20260714073140 ran before its SQL was corrected';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('sequence_template');

        if ($table->hasColumn('ordre') && !$table->hasColumn('order')) {
            $this->addSql('ALTER TABLE sequence_template CHANGE ordre `order` INT NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('sequence_template');

        if ($table->hasColumn('order') && !$table->hasColumn('ordre')) {
            $this->addSql('ALTER TABLE sequence_template CHANGE `order` ordre INT NOT NULL');
        }
    }
}
