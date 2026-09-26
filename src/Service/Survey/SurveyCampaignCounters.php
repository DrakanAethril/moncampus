<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Counter\CounterDrift;
use App\Counter\CounterRun;
use App\Counter\RecomputableCounter;
use App\Entity\SurveyCampaign;
use Doctrine\DBAL\Connection;

/**
 * The two stored counts of a survey campaign - targeted, responded - in one place: the live
 * increments and the recomputation that checks them.
 *
 * Their source is `survey_target`: one row per person aimed at, `responded_at` stamped when that
 * person submits. The rows are only ever added (App\Service\Survey\SurveyTargetResolver) and the
 * stamp only ever set once (App\Service\Survey\SurveyResponseRecorder), so the live side is two
 * increments, called by those two classes inside the transaction that writes the rows.
 *
 * What moves the source without passing through them is a deleted account: its target rows go with
 * it (ON DELETE CASCADE). That drift is real and rare, and the nightly pass is what corrects it.
 */
class SurveyCampaignCounters implements RecomputableCounter
{
    public const string NAME = 'survey_responses';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Sondages : personnes ciblées et réponses de chaque campagne';
    }

    public function targetsAdded(SurveyCampaign $campaign, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE survey_campaign SET targeted_count = targeted_count + :count WHERE id = :id',
            ['count' => $count, 'id' => $campaign->getId()],
        );
        $campaign->mirrorCounts($count, 0);
    }

    public function responseAdded(SurveyCampaign $campaign): void
    {
        $this->connection->executeStatement(
            'UPDATE survey_campaign SET responded_count = responded_count + 1 WHERE id = :id',
            ['id' => $campaign->getId()],
        );
        $campaign->mirrorCounts(0, 1);
    }

    public function recompute(?int $id = null, bool $dryRun = false): CounterRun
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $dryRun): CounterRun {
            $parameters = null !== $id ? ['id' => $id] : [];

            /** @var list<array{id: int|string, name: string, targeted_count: int|string, responded_count: int|string}> $campaigns */
            $campaigns = $connection->fetchAllAssociative(
                'SELECT id, name, targeted_count, responded_count FROM survey_campaign'
                .(null !== $id ? ' WHERE id = :id' : '').' ORDER BY id FOR UPDATE',
                $parameters,
            );

            /** @var list<array{campaign_id: int|string, targeted: int|string, responded: int|string}> $sums */
            $sums = $connection->fetchAllAssociative(
                'SELECT survey_campaign_id AS campaign_id, COUNT(*) AS targeted, COUNT(responded_at) AS responded
                   FROM survey_target'.(null !== $id ? ' WHERE survey_campaign_id = :id' : '').'
                  GROUP BY survey_campaign_id',
                $parameters,
            );

            $expected = [];
            foreach ($sums as $sum) {
                $expected[(int) $sum['campaign_id']] = ['targeted_count' => (int) $sum['targeted'], 'responded_count' => (int) $sum['responded']];
            }

            $drifts = [];
            foreach ($campaigns as $campaign) {
                $campaignId = (int) $campaign['id'];
                $stored = ['targeted_count' => (int) $campaign['targeted_count'], 'responded_count' => (int) $campaign['responded_count']];
                $computed = $expected[$campaignId] ?? ['targeted_count' => 0, 'responded_count' => 0];

                if ($stored === $computed) {
                    continue;
                }

                $drifts[] = new CounterDrift($campaignId, $campaign['name'], $stored, $computed);

                if (!$dryRun) {
                    $connection->executeStatement(
                        'UPDATE survey_campaign SET targeted_count = :targeted_count, responded_count = :responded_count WHERE id = :id',
                        $computed + ['id' => $campaignId],
                    );
                }
            }

            return new CounterRun(self::NAME, \count($campaigns), $drifts, $dryRun);
        });
    }
}
