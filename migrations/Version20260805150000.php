<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Support notes on a student's job search (screen 2a, "Notes d'accompagnement").
 *
 * Attached to the student rather than to one application, because that is how they read: "at ease
 * speaking, cover letter to shorten" is an observation about the search, not about one company.
 *
 * Never shown to the student, by design: a note written to be read between teachers stops being
 * written honestly the day the student can read it too.
 */
final class Version20260805150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Adds job_search_note: the team's support notes on a student's job search (screen 2a).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job_search_note (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_F7CBF434F675F31B (author_id), INDEX idx_job_search_note_student (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE job_search_note ADD CONSTRAINT FK_F7CBF434CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE job_search_note ADD CONSTRAINT FK_F7CBF434F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job_search_note DROP FOREIGN KEY FK_F7CBF434CB944F1A');
        $this->addSql('ALTER TABLE job_search_note DROP FOREIGN KEY FK_F7CBF434F675F31B');
        $this->addSql('DROP TABLE job_search_note');
    }
}
