<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The mode contrôle setting, frozen on the launched quiz like every other launch setting.
 *
 * Four columns and no journal yet: the announcement comes before the device, never the other way
 * round. What this enables is the entry contract and the teacher's checkbox; the page-event journal
 * is the next migration.
 *
 * Every existing quiz lands on `supervised = 0`, which is what it was: nothing is switched on
 * retroactively on a quiz already out with a class.
 *
 * The `DEFAULT`s serve the ALTER alone and are dropped right after - the PHP properties carry them,
 * and a DEFAULT left in the schema makes `doctrine:schema:validate` diverge on every run.
 */
final class Version20260826100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mode contrôle settings on quiz_instance: supervised, policy, exit threshold, autosubmit count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE quiz_instance ADD supervised TINYINT(1) DEFAULT 0 NOT NULL, ADD supervision_policy VARCHAR(16) DEFAULT 'warn' NOT NULL, ADD supervision_exit_secs SMALLINT UNSIGNED DEFAULT 8 NOT NULL, ADD supervision_submit_at SMALLINT UNSIGNED DEFAULT NULL");
        $this->addSql('ALTER TABLE quiz_instance ALTER supervised DROP DEFAULT');
        $this->addSql('ALTER TABLE quiz_instance ALTER supervision_policy DROP DEFAULT');
        $this->addSql('ALTER TABLE quiz_instance ALTER supervision_exit_secs DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz_instance DROP supervised, DROP supervision_policy, DROP supervision_exit_secs, DROP supervision_submit_at');
    }
}
