<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyCampaign;
use App\Entity\SurveyTarget;
use App\Entity\User;
use App\Repository\SurveyTargetRepository;
use App\Service\AudienceResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who a campaign aims at, written down once - and topped up while it stays open, never trimmed
 * (design/validated/surveys.md §7.2).
 *
 * The audience itself is resolved by the shared App\Service\AudienceResolver: no new audience rule
 * is written for surveys. What this class adds is the *freezing* - one survey_target row per
 * person, which is the denominator of the response rate and the list of who to remind. Resolving
 * the audience at display time instead would give a percentage that moves on its own between two
 * readings.
 *
 * The one rule, and it only goes one way:
 *
 * > While the campaign is open, refreshing **adds** the people now aimed at. Nothing is ever
 * > removed. Once it closes, the target stops moving.
 *
 * Not removing is deliberate: a student who answered and then left the class did answer, and their
 * opinion counts in the measurement that was made.
 */
class SurveyTargetResolver
{
    // Rows per flush while freezing an institution-wide target - the same batching the class import
    // uses, for the same reason: a campaign aimed at the whole school is counted in hundreds of
    // rows (surveys.md §7.11).
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly AudienceResolver $audienceResolver,
        private readonly SurveyTargetRepository $targets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Writes the missing target rows and returns how many were added. Idempotent: called twice in a
     * row, the second call adds nothing.
     *
     * Does not flush the campaign itself - the caller owns the transaction, because freezing the
     * target is one of the four things a launch does at once.
     */
    public function refresh(SurveyCampaign $campaign): int
    {
        $recipients = $this->audienceResolver->resolveRecipients($campaign);
        $already = array_flip($this->targets->findTargetedUserIds($campaign));

        $added = 0;
        foreach ($recipients as $user) {
            $id = $user->getId();

            if (null === $id || isset($already[$id])) {
                continue;
            }

            $this->entityManager->persist(new SurveyTarget($campaign, $user));
            $already[$id] = true;
            ++$added;

            if (0 === $added % self::BATCH_SIZE) {
                $this->entityManager->flush();
            }
        }

        if ($added > 0) {
            $this->entityManager->flush();
        }

        return $added;
    }

    /**
     * The same refresh, but only while the campaign is still open - what the results screen calls
     * on every display. A closed campaign's target is a fact, and re-resolving it would rewrite a
     * measurement already made.
     */
    public function refreshIfOpen(SurveyCampaign $campaign, ?\DateTimeImmutable $now = null): int
    {
        return $campaign->isOpenNow($now) ? $this->refresh($campaign) : 0;
    }

    /**
     * How many people the audience would reach right now - what the launch screen prints on its
     * button (« Lancer — 47 personnes »). Reads the audience, writes nothing.
     *
     * @return list<User>
     */
    public function preview(SurveyCampaign $campaign): array
    {
        return $this->audienceResolver->resolveRecipients($campaign);
    }
}
