<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Survey campaigns store their two response counts - targeted, responded - instead of counting
 * `survey_target` on every list and results screen.
 *
 * The columns are filled from `survey_target` in the same migration, so they are right on the day
 * of the deployment; from then on App\Service\Survey\SurveyCampaignCounters moves them, and
 * `app:counters:recompute` checks them.
 */
final class Version20260926072651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the targeted and responded counts on survey_campaign';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey_campaign ADD targeted_count INT DEFAULT 0 NOT NULL, ADD responded_count INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE survey_campaign c
            JOIN (SELECT survey_campaign_id, COUNT(*) AS targeted, COUNT(responded_at) AS responded FROM survey_target GROUP BY survey_campaign_id) t
              ON t.survey_campaign_id = c.id
            SET c.targeted_count = t.targeted, c.responded_count = t.responded');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE survey_campaign DROP targeted_count, DROP responded_count');
    }
}
