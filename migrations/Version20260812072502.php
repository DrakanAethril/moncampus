<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The link from a travail à faire to the video it asks to watch - the exact counterpart of
 * assignment.audio_recording_id, and the last column the "Visionnage" nature needed.
 *
 * ON DELETE SET NULL, as on the audio side: a deleted video must not take the travail with it, and
 * the travail falling back to no video is what lets the teacher point it at another one.
 */
final class Version20260812072502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattache un travail à faire à la vidéo qu\'il demande de visionner.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment ADD video_resource_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE assignment ADD CONSTRAINT FK_30C544BAABB02C84 FOREIGN KEY (video_resource_id) REFERENCES video_resource (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_30C544BAABB02C84 ON assignment (video_resource_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP FOREIGN KEY FK_30C544BAABB02C84');
        $this->addSql('DROP INDEX IDX_30C544BAABB02C84 ON assignment');
        $this->addSql('ALTER TABLE assignment DROP video_resource_id');
    }
}
