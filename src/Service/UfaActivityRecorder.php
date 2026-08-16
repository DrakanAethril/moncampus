<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\UfaActivity;
use App\Entity\User;
use App\Enum\UfaActivityType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only write point of the UFA log (App\Entity\UfaActivity). Adding a tracked action = a case in
 * App\Enum\UfaActivityType, its translation key, and a call here.
 *
 * To be called AFTER the flush of the action observed: the log records it, it does not take part in
 * its transaction. A log write that failed must not cancel a signature.
 *
 * The payload is filled in here and not by the callers, so that the same sentence is composable
 * whatever screen the action started from - the tutor signing themselves and the staff signing on
 * their behalf produce the same row, only the actor changes.
 */
class UfaActivityRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @param array<string, string> $extraPayload */
    public function record(
        UfaActivityType $type,
        InternshipTutorLink $tutorLink,
        ?User $actor,
        ?InternshipEvaluationPeriod $period = null,
        array $extraPayload = [],
    ): void {
        $student = $tutorLink->getStudent();
        $tutor = $tutorLink->getTutor();

        $activity = new UfaActivity($type, $actor, [
            'student' => $this->name($student),
            'tutor' => $this->name($tutor),
            'actor' => $this->name($actor),
            'period' => $period?->getName() ?? '',
            // For the sentences that mention the period in parentheses: without this pre-composed
            // suffix, an engagement reminder - which bears on no period - displayed empty
            // parentheses.
            'periodSuffix' => null !== $period ? ' ('.$period->getName().')' : '',
            ...$extraPayload,
        ]);

        $activity->setTutorLink($tutorLink);
        $activity->setEvaluationPeriod($period);
        $activity->setProgram($tutorLink->getProgram());
        $activity->setTestData($tutorLink->isTestAlternance());

        $this->entityManager->persist($activity);
        $this->entityManager->flush();
    }

    private function name(?User $user): string
    {
        return $user?->getDisplayName() ?? $user?->getUsername() ?? '';
    }
}
