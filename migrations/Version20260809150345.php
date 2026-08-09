<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes lesson_session on (teacher, day) and (program, day), the two shapes every timetable and
 * dashboard query filters as a range and which only Doctrine's foreign-key indexes covered.
 */
final class Version20260809150345 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index lesson_session on (teacher_id|program_id, day, start_hour)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_lesson_session_teacher_day ON lesson_session (teacher_id, day, start_hour)');
        $this->addSql('CREATE INDEX idx_lesson_session_program_day ON lesson_session (program_id, day, start_hour)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_lesson_session_teacher_day ON lesson_session');
        $this->addSql('DROP INDEX idx_lesson_session_program_day ON lesson_session');
    }
}
