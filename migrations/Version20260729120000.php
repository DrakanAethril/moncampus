<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Role dashboards (design_handoff_dashboards): Assignment gains a nature (à rendre / à réviser /
 * à préparer / à lire) and Cohort a formation color for the dashboard chips/matrix/legend.
 */
final class Version20260729120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add assignment.nature and cohort.color for the role dashboards';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT 'to_submit' backfills every pre-existing row (they all predate natures and were
        // submission boxes by definition). Not kept as a permanent column default: new rows always
        // set it explicitly via Assignment's PHP-level default/form field (same pattern as
        // internship_tutor_link.contract_type in Version20260729031829).
        $this->addSql("ALTER TABLE assignment ADD nature VARCHAR(20) DEFAULT 'to_submit' NOT NULL");
        $this->addSql('ALTER TABLE assignment ALTER COLUMN nature DROP DEFAULT');
        $this->addSql('ALTER TABLE cohort ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assignment DROP nature');
        $this->addSql('ALTER TABLE cohort DROP color');
    }
}
