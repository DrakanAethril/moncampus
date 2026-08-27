<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Relevés stop belonging to an evaluation period.
 *
 * Until now there was one attendance statement per week *of a period* and exactly one council *per
 * period* - `class_council_mention` even carried a unique index on (student, period) saying so. A
 * team could therefore hold neither four councils in a two-period year nor one across both, and the
 * count of documents was decided by a calendar that exists for something else.
 *
 * `game_statement` replaces both: a **named** document with a **type**, created by hand, as many or
 * as few as a team wants. The type decides the fields - an attendance relevé covers a span and
 * carries a periodicity, a council carries a label and a day - and `game_statement_line` carries the
 * two disjoint payloads: a three-state answer, or a mention and a comment.
 *
 * The periods still carry the **index**: the game invents no calendar, so a relevé's points are
 * filed into the period its own date falls in. What changed is that the calendar no longer decides
 * how many documents get filled in - only where their points land. A relevé held outside every
 * period keeps its content and credits nothing, and the screen says so.
 *
 * `reward_grant.period_id` becomes nullable for the same family of reason: a reward for a mock exam
 * or an open day belongs to the establishment's calendar, not to the game's.
 *
 * **Nothing is migrated across.** The three tables are dropped rather than converted: the game has
 * never been switched on in production - `Feature::Game` is off for every role and
 * `program.game_enabled` is false everywhere - so they are empty by construction, and a conversion
 * nobody can exercise is a conversion nobody should trust.
 */
final class Version20260828080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Named, typed relevés replacing the period-bound attendance statements and council mentions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_statement (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, name VARCHAR(160) NOT NULL, held_on DATE NOT NULL, periodicity VARCHAR(10) DEFAULT NULL, starts_on DATE DEFAULT NULL, ends_on DATE DEFAULT NULL, weeks_covered INT DEFAULT NULL, created_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, program_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_127D90743EB8070A (program_id), INDEX IDX_127D9074F675F31B (author_id), INDEX idx_game_statement_program_type (program_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_statement_line (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(20) DEFAULT NULL, mention VARCHAR(20) DEFAULT NULL, comment LONGTEXT DEFAULT NULL, stated_at DATETIME DEFAULT NULL, statement_id INT NOT NULL, student_id INT NOT NULL, stated_by_id INT DEFAULT NULL, INDEX IDX_19FEE896849CB65B (statement_id), INDEX IDX_19FEE896CB944F1A (student_id), INDEX IDX_19FEE896837FE29F (stated_by_id), UNIQUE INDEX uniq_game_statement_line (statement_id, student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game_statement ADD CONSTRAINT FK_127D90743EB8070A FOREIGN KEY (program_id) REFERENCES program (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_statement ADD CONSTRAINT FK_127D9074F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_statement_line ADD CONSTRAINT FK_19FEE896849CB65B FOREIGN KEY (statement_id) REFERENCES game_statement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_statement_line ADD CONSTRAINT FK_19FEE896CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_statement_line ADD CONSTRAINT FK_19FEE896837FE29F FOREIGN KEY (stated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('DROP TABLE attendance_statement_line');
        $this->addSql('DROP TABLE attendance_statement');
        $this->addSql('DROP TABLE class_council_mention');

        $this->addSql('ALTER TABLE reward_grant CHANGE period_id period_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_statement_line');
        $this->addSql('DROP TABLE game_statement');
        $this->addSql('ALTER TABLE reward_grant CHANGE period_id period_id INT NOT NULL');
    }
}
