<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the column defaults Version20260806095638 left on assignment's five booleans.
 *
 * They were there for a good reason - the columns were added NOT NULL to a table that already had
 * rows, and a DEFAULT is how those rows get a value - but the entity declares no default, so every
 * database built by replaying the migrations ended up one step away from its own mapping and
 * `doctrine:schema:validate` refused it. Nothing reads the default: the app only ever writes
 * through Doctrine, which always supplies these fields, and no migration INSERTs into assignment.
 */
final class Version20260809142417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Drop assignment's leftover boolean column defaults, which put a migrated schema out of sync with the mapping";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment CHANGE mandatory mandatory TINYINT NOT NULL, CHANGE graded graded TINYINT NOT NULL, CHANGE grading_visible_to_students grading_visible_to_students TINYINT NOT NULL, CHANGE late_submission_allowed late_submission_allowed TINYINT NOT NULL, CHANGE read_tracking_enabled read_tracking_enabled TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment CHANGE mandatory mandatory TINYINT DEFAULT 1 NOT NULL, CHANGE graded graded TINYINT DEFAULT 0 NOT NULL, CHANGE grading_visible_to_students grading_visible_to_students TINYINT DEFAULT 1 NOT NULL, CHANGE late_submission_allowed late_submission_allowed TINYINT DEFAULT 0 NOT NULL, CHANGE read_tracking_enabled read_tracking_enabled TINYINT DEFAULT 1 NOT NULL');
    }
}
