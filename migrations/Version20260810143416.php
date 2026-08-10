<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * An audience is now a set of types rather than one - see App\Entity\AudienceTargetable. The
 * single-valued audience_type column becomes the simple_array audience_types on all four
 * targetable tables.
 *
 * Each ALTER is split in three (add, copy, drop) rather than left as the generated
 * "ADD ... , DROP ..." single statement, which would have thrown every existing audience away.
 * The copy is a plain assignment because a one-element set serialises to exactly the old value:
 * simple_array is a comma-joined string, so 'all_teachers' is already the correct storage for
 * [AllTeachers].
 */
final class Version20260810143416 extends AbstractMigration
{
    /** @var list<string> */
    private const array TABLES = ['agenda_event', 'announcement', 'message_thread', 'signup_list'];

    public function getDescription(): string
    {
        return 'Audience targets carry a set of audience types instead of a single one';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD audience_types LONGTEXT DEFAULT NULL', $table));
            $this->addSql(\sprintf('UPDATE %s SET audience_types = audience_type', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP audience_type', $table));
        }
    }

    /**
     * Lossy by nature, and deliberately so: a set of several types cannot be expressed in the
     * column it goes back to, so only the first one survives. Nothing else could - restoring the
     * old shape means picking one.
     */
    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD audience_type VARCHAR(20) DEFAULT NULL', $table));
            $this->addSql(\sprintf("UPDATE %s SET audience_type = SUBSTRING_INDEX(COALESCE(audience_types, 'manual'), ',', 1)", $table));
            $this->addSql(\sprintf('ALTER TABLE %s CHANGE audience_type audience_type VARCHAR(20) NOT NULL', $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP audience_types', $table));
        }
    }
}
