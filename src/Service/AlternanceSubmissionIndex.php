<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;

/**
 * Everything the step rule asks about a set of alternances, read up front in a handful of queries
 * rather than four per alternance and per bilan.
 *
 * AlternancePeriodStatusResolver answers the same questions one alternance at a time, which is the
 * right shape for a single livret's screen and the wrong one for a board that walks every
 * alternance of every formation (AlternanceReminderBoard, and the staff dashboard banner behind
 * it). Handing the resolver one of these changes nothing about *what* it decides - the ordering
 * and lateness rules stay written once, in the resolver - only where it reads the submissions
 * from.
 *
 * Ids, not objects: the maps come straight out of scalar queries, so nothing is hydrated here for
 * the sake of a null test.
 */
final readonly class AlternanceSubmissionIndex
{
    /**
     * @param array<int, true>             $fullySignedEngagementLinkIds tutor link id => the 3 engagement signatures are in
     * @param array<int, array<int, true>> $signedTutorEvaluations       tutor link id => evaluation period id => signed
     * @param array<int, array<int, true>> $signedStudentEvaluations     student id => evaluation period id => signed
     * @param array<int, array<int, true>> $closedSupervisorEvaluations  tutor link id => evaluation period id => closed
     */
    public function __construct(
        private array $fullySignedEngagementLinkIds,
        private array $signedTutorEvaluations,
        private array $signedStudentEvaluations,
        private array $closedSupervisorEvaluations,
    ) {
    }

    public function isEngagementComplete(InternshipTutorLink $tutorLink): bool
    {
        return isset($this->fullySignedEngagementLinkIds[(int) $tutorLink->getId()]);
    }

    public function isTutorSigned(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        return isset($this->signedTutorEvaluations[(int) $tutorLink->getId()][(int) $period->getId()]);
    }

    public function isStudentSigned(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        $student = $tutorLink->getStudent();

        return null !== $student && isset($this->signedStudentEvaluations[(int) $student->getId()][(int) $period->getId()]);
    }

    public function isSupervisorClosed(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        return isset($this->closedSupervisorEvaluations[(int) $tutorLink->getId()][(int) $period->getId()]);
    }
}
