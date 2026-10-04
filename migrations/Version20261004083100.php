<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `ufa_ecf`: lit for the administration (ROLE_STAFF, ROLE_STAFF-LEAD), who
 * read the ECF booklet on the alternance follow-up, off for everybody else. Administrators have
 * everything by construction. Written rather than left absent for the reason
 * Version20260916151000 gives: an absent pair would fall back on today's catalogue.
 */
final class Version20261004083100 extends AbstractMigration
{
    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    private const array LIT = ['ROLE_STAFF', 'ROLE_STAFF-LEAD'];

    public function getDescription(): string
    {
        return 'Seed the ufa_ecf feature in the role matrix, lit for staff and staff-lead';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLES as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('ufa_ecf', '".$role."', ".(\in_array($role, self::LIT, true) ? 1 : 0).')');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'ufa_ecf'");
    }
}
