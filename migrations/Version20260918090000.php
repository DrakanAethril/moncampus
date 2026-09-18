<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The detail of a video watching: time really played, skips and losses of focus.
 *
 * video_watch_progress gains its counters and two dates; video_watch_event holds each skip (from, to)
 * and each loss of focus. Rows already there start at zero: nothing was measured before, and the
 * statistics print the counters as they are. completed_at stays null on them, the readers falling
 * back on last_watched_at, which is what they read until now.
 *
 * The DEFAULT 0 only serves the ALTER on a populated table and is dropped right after, the mapping
 * declaring none.
 */
final class Version20260918090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vidéo suivie : temps de lecture, sauts et pertes de focus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_watch_event (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, position_seconds INT NOT NULL, target_seconds INT DEFAULT NULL, occurred_at DATETIME NOT NULL, progress_id INT NOT NULL, INDEX IDX_EB1F58E843DB87C9 (progress_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE video_watch_event ADD CONSTRAINT FK_EB1F58E843DB87C9 FOREIGN KEY (progress_id) REFERENCES video_watch_progress (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_watch_progress ADD first_watched_at DATETIME DEFAULT NULL, ADD completed_at DATETIME DEFAULT NULL, ADD watched_seconds INT DEFAULT 0 NOT NULL, ADD skip_count INT DEFAULT 0 NOT NULL, ADD focus_loss_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE video_watch_progress ALTER watched_seconds DROP DEFAULT, ALTER skip_count DROP DEFAULT, ALTER focus_loss_count DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE video_watch_event');
        $this->addSql('ALTER TABLE video_watch_progress DROP first_watched_at, DROP completed_at, DROP watched_seconds, DROP skip_count, DROP focus_loss_count');
    }
}
