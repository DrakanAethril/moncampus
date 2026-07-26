<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726051323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Program form redesign: per-audience visibility (timetable/syllabus/alternance calendar/program), syllabus and alternance-calendar file-upload modes, and Modality::isAlternance - replaces Program.alternance_calendar_enabled with alternance_calendar_visibility, preserving each row's current true/false meaning.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modality ADD is_alternance TINYINT DEFAULT 0 NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE program
                ADD visibility VARCHAR(20) DEFAULT 'staff_admin' NOT NULL,
                ADD timetable_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                ADD syllabus_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                ADD syllabus_mode VARCHAR(20) DEFAULT 'topics' NOT NULL,
                ADD syllabus_file_key VARCHAR(255) DEFAULT NULL,
                ADD alternance_calendar_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                ADD alternance_calendar_mode VARCHAR(20) DEFAULT 'period' NOT NULL,
                ADD alternance_calendar_file_key VARCHAR(255) DEFAULT NULL
            SQL);
        // Preserve each row's current true/false meaning before the old boolean is dropped - the
        // old gate had no role tiering, so true -> visible to everyone, false -> hidden is an
        // exact behavior-preserving translation.
        $this->addSql("UPDATE program SET alternance_calendar_visibility = 'hidden' WHERE alternance_calendar_enabled = 0");
        $this->addSql('ALTER TABLE program DROP alternance_calendar_enabled');
        // Drop the transient SQL-level defaults used only to satisfy NOT NULL above while
        // preserving existing rows - the entity itself doesn't declare column defaults for these,
        // every Program is always constructed with an explicit value.
        $this->addSql(<<<'SQL'
            ALTER TABLE program
                CHANGE visibility visibility VARCHAR(20) NOT NULL,
                CHANGE timetable_visibility timetable_visibility VARCHAR(20) NOT NULL,
                CHANGE syllabus_visibility syllabus_visibility VARCHAR(20) NOT NULL,
                CHANGE syllabus_mode syllabus_mode VARCHAR(20) NOT NULL,
                CHANGE alternance_calendar_visibility alternance_calendar_visibility VARCHAR(20) NOT NULL,
                CHANGE alternance_calendar_mode alternance_calendar_mode VARCHAR(20) NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modality DROP is_alternance');
        $this->addSql(<<<'SQL'
            ALTER TABLE program
                CHANGE visibility visibility VARCHAR(20) DEFAULT 'staff_admin' NOT NULL,
                CHANGE timetable_visibility timetable_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                CHANGE syllabus_visibility syllabus_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                CHANGE syllabus_mode syllabus_mode VARCHAR(20) DEFAULT 'topics' NOT NULL,
                CHANGE alternance_calendar_visibility alternance_calendar_visibility VARCHAR(20) DEFAULT 'everyone' NOT NULL,
                CHANGE alternance_calendar_mode alternance_calendar_mode VARCHAR(20) DEFAULT 'period' NOT NULL
            SQL);
        $this->addSql("ALTER TABLE program ADD alternance_calendar_enabled TINYINT DEFAULT 0 NOT NULL");
        $this->addSql("UPDATE program SET alternance_calendar_enabled = 1 WHERE alternance_calendar_visibility <> 'hidden'");
        $this->addSql(<<<'SQL'
            ALTER TABLE program
                DROP visibility,
                DROP timetable_visibility,
                DROP syllabus_visibility,
                DROP syllabus_mode,
                DROP syllabus_file_key,
                DROP alternance_calendar_visibility,
                DROP alternance_calendar_mode,
                DROP alternance_calendar_file_key
            SQL);
    }
}
