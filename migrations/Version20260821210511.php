<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La trace d'une console : ce qui a été affiché à l'écran.
 *
 * On enregistre le panneau, jamais les touches. `transcript_stable_length` est la frontière entre ce
 * qui a défilé - définitif - et l'écran tel qu'il est encore, réécrit à chaque échange.
 */
final class Version20260821210511 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Console des machines : la transcription d\'une session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE console_session ADD transcript LONGTEXT DEFAULT NULL, ADD transcript_stable_length INT DEFAULT 0 NOT NULL, ADD transcript_truncated TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE console_session DROP transcript, DROP transcript_stable_length, DROP transcript_truncated');
    }
}
