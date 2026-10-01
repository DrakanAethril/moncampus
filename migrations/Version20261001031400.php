<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The role matrix gains `company_search` and `enterprise_pool`, **off for every role**.
 *
 * Data only. The establishment asked for both to ship visible to the administrator alone - who has
 * everything by construction and therefore no column - and to be opened from Gestion >
 * Fonctionnalités once tried. Written rather than left absent for the reason
 * Version20260916151000 gives: an absent pair would fall back on today's catalogue.
 */
final class Version20261001031400 extends AbstractMigration
{
    private const array FEATURES = ['company_search', 'enterprise_pool'];

    private const array ROLES = ['ROLE_STUDENT', 'ROLE_TEACHER', 'ROLE_STAFF', 'ROLE_STAFF-LEAD', 'ROLE_TUTOR', 'ROLE_SUPPORT-TECH', 'ROLE_ECO', 'ROLE_EXTERNAL'];

    public function getDescription(): string
    {
        return 'Seed the company_search and enterprise_pool features in the role matrix, off for every role';
    }

    public function up(Schema $schema): void
    {
        foreach (self::FEATURES as $feature) {
            foreach (self::ROLES as $role) {
                $this->addSql("INSERT INTO feature_role_setting (feature, role, enabled) VALUES ('".$feature."', '".$role."', 0)");
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_role_setting WHERE feature IN ('company_search', 'enterprise_pool')");
    }
}
