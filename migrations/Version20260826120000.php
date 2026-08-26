<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The supervision journal, and the session that owns a supervised attempt.
 *
 * One row per fact, never a counter on the attempt: four 400 ms flickers and one forty-second
 * absence would give the same integer and have nothing to do with each other.
 *
 * The instant is kept to the millisecond, in two columns rather than in one `DATETIME(3)`: Doctrine's
 * own `datetime_immutable` reads and writes to the second, and a custom DBAL type carrying the
 * fraction leaves `doctrine:schema:validate` asking for the same ALTER on every run. Two beacons can
 * land inside the same second, and the order between "left" and "came back" is what produces the
 * duration - a second's precision would manufacture absences of 0 s.
 *
 * The rows are dropped with their attempt (`ON DELETE CASCADE`), which is the only way they ever
 * disappear other than the 12-month purge announced on the entry contract.
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supervision journal (quiz_attempt_event) and the owning session key on quiz_attempt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_attempt_event (
            id INT AUTO_INCREMENT NOT NULL,
            attempt_id INT NOT NULL,
            position SMALLINT UNSIGNED DEFAULT NULL,
            type VARCHAR(32) NOT NULL,
            occurred_at DATETIME NOT NULL,
            occurred_ms SMALLINT UNSIGNED NOT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            client VARCHAR(16) NOT NULL,
            INDEX idx_attempt_position (attempt_id, position),
            INDEX IDX_B1DE8745B191BE6B (attempt_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE quiz_attempt_event ADD CONSTRAINT FK_B1DE8745B191BE6B FOREIGN KEY (attempt_id) REFERENCES quiz_attempt (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_attempt ADD session_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_attempt_event DROP FOREIGN KEY FK_B1DE8745B191BE6B');
        $this->addSql('DROP TABLE quiz_attempt_event');
        $this->addSql('ALTER TABLE quiz_attempt DROP session_key');
    }
}
