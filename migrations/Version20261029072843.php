<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The serious games gain a second mechanic, the **scenario** (design/validated/serious-games-neko-con.md
 * §3.4), on the existing tables so that the reading of results and the statistics stay common to
 * every game:
 *
 * - `serious_game.mechanic`: `series` for the four games already there, `scenario` for Panique à la
 *   Neko-Con, read from the manifest at each sync;
 * - `serious_game_unit_state`: where a ticket stands in the current game - its status, the step in
 *   progress (by identifier), Inès' guided questions passed, the help asked, the scenario's version
 *   it was started with. Null for the series;
 * - `serious_game_answer`: the step answered and whether it was a guided question.
 *
 * The two NOT NULL columns keep their DEFAULT: the entities declare it.
 */
final class Version20261029072843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Serious games: the scenario mechanic (ticket state, step answered)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game ADD mechanic VARCHAR(20) DEFAULT \'series\' NOT NULL');
        $this->addSql('ALTER TABLE serious_game_answer ADD step_id VARCHAR(40) DEFAULT NULL, ADD help TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE serious_game_unit_state ADD status VARCHAR(20) DEFAULT NULL, ADD step_id VARCHAR(40) DEFAULT NULL, ADD guide_step SMALLINT DEFAULT NULL, ADD hints SMALLINT DEFAULT NULL, ADD scenario_version INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE serious_game DROP mechanic');
        $this->addSql('ALTER TABLE serious_game_answer DROP step_id, DROP help');
        $this->addSql('ALTER TABLE serious_game_unit_state DROP status, DROP step_id, DROP guide_step, DROP hints, DROP scenario_version');
    }
}
