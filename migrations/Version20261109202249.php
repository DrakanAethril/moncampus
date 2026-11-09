<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PHP pas à pas: a closed ticket played again is a session of mode `replay`, which keeps its own step and
 * written hints - the ticket's state stays closed.
 */
final class Version20261109202249 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serious games: step and hints of a replayed ticket';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game_session ADD replay_step_id VARCHAR(40) DEFAULT NULL, ADD replay_hints SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game_session DROP replay_step_id, DROP replay_hints');
    }
}
