<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces MessageAudienceType::ProgramStudents/ProgramTeachers with a single Program case that
 * can target several Programs at once (agenda_event/announcement/message_thread_program join
 * tables, replacing the old single program_id FK) and independently include students and/or
 * teachers (include_students/include_teachers). Existing rows are converted rather than dropped:
 * a program_students row becomes Program + its one program + students-only, a program_teachers
 * row becomes Program + its one program + teachers-only.
 */
final class Version20260715125522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unify program_students/program_teachers into one multi-program, combinable-roles Program audience type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agenda_event_program (agenda_event_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_2A10FF4770AF5DEF (agenda_event_id), INDEX IDX_2A10FF473EB8070A (program_id), PRIMARY KEY (agenda_event_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE announcement_program (announcement_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_6E4C2A4E913AEA17 (announcement_id), INDEX IDX_6E4C2A4E3EB8070A (program_id), PRIMARY KEY (announcement_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message_thread_program (message_thread_id INT NOT NULL, program_id INT NOT NULL, INDEX IDX_7DC0CB858829462F (message_thread_id), INDEX IDX_7DC0CB853EB8070A (program_id), PRIMARY KEY (message_thread_id, program_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE agenda_event_program ADD CONSTRAINT FK_2A10FF4770AF5DEF FOREIGN KEY (agenda_event_id) REFERENCES agenda_event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE agenda_event_program ADD CONSTRAINT FK_2A10FF473EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcement_program ADD CONSTRAINT FK_6E4C2A4E913AEA17 FOREIGN KEY (announcement_id) REFERENCES announcement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE announcement_program ADD CONSTRAINT FK_6E4C2A4E3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_thread_program ADD CONSTRAINT FK_7DC0CB858829462F FOREIGN KEY (message_thread_id) REFERENCES message_thread (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message_thread_program ADD CONSTRAINT FK_7DC0CB853EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');

        // New columns added with a default first (so existing rows are never briefly NOT NULL-
        // invalid), then explicitly overwritten per row below from the old audience_type - the
        // default only matters for the brief moment before that UPDATE runs.
        $this->addSql('ALTER TABLE agenda_event DROP FOREIGN KEY `FK_6B965E393EB8070A`');
        $this->addSql('DROP INDEX IDX_6B965E393EB8070A ON agenda_event');
        $this->addSql('ALTER TABLE agenda_event ADD include_students TINYINT NOT NULL DEFAULT 1, ADD include_teachers TINYINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE announcement DROP FOREIGN KEY `FK_4DB9D91C3EB8070A`');
        $this->addSql('DROP INDEX IDX_4DB9D91C3EB8070A ON announcement');
        $this->addSql('ALTER TABLE announcement ADD include_students TINYINT NOT NULL DEFAULT 1, ADD include_teachers TINYINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE message_thread DROP FOREIGN KEY `FK_607D18C3EB8070A`');
        $this->addSql('DROP INDEX IDX_607D18C3EB8070A ON message_thread');
        $this->addSql('ALTER TABLE message_thread ADD include_students TINYINT NOT NULL DEFAULT 1, ADD include_teachers TINYINT NOT NULL DEFAULT 1');

        foreach (['agenda_event', 'announcement', 'message_thread'] as $table) {
            $joinTable = $table.'_program';
            $fkColumn = $table.'_id';

            $this->addSql(\sprintf(
                'INSERT INTO %s (%s, program_id) SELECT id, program_id FROM %s WHERE program_id IS NOT NULL',
                $joinTable,
                $fkColumn,
                $table,
            ));
            $this->addSql(\sprintf("UPDATE %s SET include_students = 1, include_teachers = 0 WHERE audience_type = 'program_students'", $table));
            $this->addSql(\sprintf("UPDATE %s SET include_students = 0, include_teachers = 1 WHERE audience_type = 'program_teachers'", $table));
            $this->addSql(\sprintf("UPDATE %s SET audience_type = 'program' WHERE audience_type IN ('program_students', 'program_teachers')", $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP program_id', $table));
            // Drops the DEFAULT clause used above to backfill existing rows - the entity mapping
            // has no DB-level default (every row always sets these explicitly), so this just keeps
            // doctrine:schema:validate clean going forward.
            $this->addSql(\sprintf('ALTER TABLE %s CHANGE include_students include_students TINYINT NOT NULL, CHANGE include_teachers include_teachers TINYINT NOT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agenda_event ADD program_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE announcement ADD program_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message_thread ADD program_id INT DEFAULT NULL');

        // Best-effort reverse, lossy by nature: a Program audience with more than one Program can
        // only keep one (arbitrarily, the lowest id) since program_id is single-valued again, and
        // "both roles" collapses to program_students (there's no third case to reverse into).
        foreach (['agenda_event', 'announcement', 'message_thread'] as $table) {
            $joinTable = $table.'_program';
            $fkColumn = $table.'_id';

            $this->addSql(\sprintf(
                'UPDATE %s t SET program_id = (SELECT MIN(jt.program_id) FROM %s jt WHERE jt.%s = t.id)',
                $table,
                $joinTable,
                $fkColumn,
            ));
            $this->addSql(\sprintf("UPDATE %s SET audience_type = 'program_teachers' WHERE audience_type = 'program' AND include_students = 0 AND include_teachers = 1", $table));
            $this->addSql(\sprintf("UPDATE %s SET audience_type = 'program_students' WHERE audience_type = 'program' AND NOT (include_students = 0 AND include_teachers = 1)", $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP include_students, DROP include_teachers', $table));
        }

        $this->addSql('ALTER TABLE agenda_event ADD CONSTRAINT `FK_6B965E393EB8070A` FOREIGN KEY (program_id) REFERENCES program (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_6B965E393EB8070A ON agenda_event (program_id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT `FK_4DB9D91C3EB8070A` FOREIGN KEY (program_id) REFERENCES program (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_4DB9D91C3EB8070A ON announcement (program_id)');
        $this->addSql('ALTER TABLE message_thread ADD CONSTRAINT `FK_607D18C3EB8070A` FOREIGN KEY (program_id) REFERENCES program (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_607D18C3EB8070A ON message_thread (program_id)');

        $this->addSql('ALTER TABLE agenda_event_program DROP FOREIGN KEY FK_2A10FF4770AF5DEF');
        $this->addSql('ALTER TABLE agenda_event_program DROP FOREIGN KEY FK_2A10FF473EB8070A');
        $this->addSql('ALTER TABLE announcement_program DROP FOREIGN KEY FK_6E4C2A4E913AEA17');
        $this->addSql('ALTER TABLE announcement_program DROP FOREIGN KEY FK_6E4C2A4E3EB8070A');
        $this->addSql('ALTER TABLE message_thread_program DROP FOREIGN KEY FK_7DC0CB858829462F');
        $this->addSql('ALTER TABLE message_thread_program DROP FOREIGN KEY FK_7DC0CB853EB8070A');
        $this->addSql('DROP TABLE agenda_event_program');
        $this->addSql('DROP TABLE announcement_program');
        $this->addSql('DROP TABLE message_thread_program');
    }
}
