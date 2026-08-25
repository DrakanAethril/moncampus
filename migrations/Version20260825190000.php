<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Three columns of the matrix are emptied: `ROLE_ECO` keeps `eco` and nothing else, `ROLE_SUPPORT-TECH`
 * and `ROLE_EXTERNAL` keep nothing at all.
 *
 * They are not "somebody who uses the platform" the way a student or a teacher is. `ROLE_ECO` exists
 * for the orienteering races and is named by one feature; the other two are outside accounts. Until
 * now all three inherited the six role-blind lines the previous migration left standing - the
 * planned evaluation, the support, and the whole alternance area - which is how a race organiser
 * came to be delivered the livret alternant.
 *
 * Nobody loses a screen they were actually using: these roles are carried alongside another one far
 * more often than on their own, and the resolver takes the most permissive of a person's roles.
 * Somebody who genuinely holds only one of them and needs a screen is given it from their card in
 * the annuaire, one person at a time.
 */
final class Version20260825190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Empty the ROLE_ECO, ROLE_SUPPORT-TECH and ROLE_EXTERNAL columns of the feature matrix';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE `role` IN ('ROLE_ECO', 'ROLE_SUPPORT-TECH', 'ROLE_EXTERNAL')");
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` = 'ROLE_ECO' AND feature = 'eco'");
    }

    /**
     * Back to what the three columns held before: the six role-blind survivors, plus e-CO for the
     * role it exists for. Written out rather than left to the previous migration, so this file
     * keeps meaning what it meant on its own.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE feature_role_setting SET enabled = 0 WHERE `role` IN ('ROLE_ECO', 'ROLE_SUPPORT-TECH', 'ROLE_EXTERNAL')");
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` IN ('ROLE_ECO', 'ROLE_SUPPORT-TECH', 'ROLE_EXTERNAL') AND feature IN ('evaluation_planning', 'support', 'ufa_booklet', 'my_alternance', 'tutor_evaluations', 'laptop_loans')");
        $this->addSql("UPDATE feature_role_setting SET enabled = 1 WHERE `role` = 'ROLE_ECO' AND feature = 'eco'");
    }
}
