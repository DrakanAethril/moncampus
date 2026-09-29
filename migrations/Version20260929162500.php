<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `portfolio`, **off for every role**.
 *
 * Data only; the tables are Version20260929162450's business. Eight zeroes are the decision the
 * establishment asked for: the portfolio ships visible to the administrator alone - who has
 * everything by construction and therefore no column - and is opened to students and teachers
 * from Gestion > Fonctionnalités once it has been tried. Written rather than left absent for the
 * reason Version20260916151000 gives: an absent pair would fall back on today's catalogue.
 */
final class Version20260929162500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the portfolio feature in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach (['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'] as $role) {
            $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('portfolio', '".$role."', 0)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature = 'portfolio'");
    }
}
