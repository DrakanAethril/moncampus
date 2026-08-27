<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The relevé d'assiduité - design/validated/gamification.md, lot 3.
 *
 * Two tables, and what they do **not** carry is the point. There is no motive column, no date of
 * absence, no counter: one row per student per unit of time, holding one of three states.
 * MonCampus has never had an attendance register and this does not create one - « pas net » says
 * that something happened that week and nothing more, so there is nothing here to keep, to rectify
 * or to protect.
 *
 * `weeks_covered` is what makes the monthly step arithmetic rather than a special case: a unit is
 * worth as many weeks as it covers, and the rate normalises the rest.
 */
final class Version20260827110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attendance statement of the campus game: three states, and no absence anywhere';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE attendance_statement (id INT AUTO_INCREMENT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, weeks_covered INT NOT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, program_id INT NOT NULL, period_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_B55FFB4C3EB8070A (program_id), INDEX IDX_B55FFB4CEC8B7ADE (period_id), INDEX IDX_B55FFB4CF675F31B (author_id), INDEX idx_attendance_statement_program_period (program_id, period_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE attendance_statement_line (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(20) NOT NULL, statement_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_BE07D373849CB65B (statement_id), INDEX IDX_BE07D373CB944F1A (student_id), UNIQUE INDEX uniq_attendance_line (statement_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE attendance_statement ADD CONSTRAINT FK_B55FFB4C3EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance_statement ADD CONSTRAINT FK_B55FFB4CEC8B7ADE FOREIGN KEY (period_id) REFERENCES evaluation_period (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance_statement ADD CONSTRAINT FK_B55FFB4CF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE attendance_statement_line ADD CONSTRAINT FK_BE07D373849CB65B FOREIGN KEY (statement_id) REFERENCES attendance_statement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE attendance_statement_line ADD CONSTRAINT FK_BE07D373CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE attendance_statement_line');
        $this->addSql('DROP TABLE attendance_statement');
    }
}
