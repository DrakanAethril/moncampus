<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;

/**
 * Cross-role gating + read-only rules shared by every one of the 3 per-period guided journeys
 * (Tuteur/Alternant/Chargé de suivi) and both of their portals (staff
 * "on-behalf" in Ufa\PeriodWizardController, self-service in InternshipTutorEvaluationController/
 * ProgramInternshipEvaluationController) - see the feature's plan doc, §Phase 5, for why the
 * within-one-role step order (1→2→3→4) is deliberately NOT enforced here: decision #3 makes a
 * role's own steps 1-3 silent, freely-revisitable drafts, only the cross-role gates below and the
 * post-signature/post-closure locks matter.
 *
 * A terminated alternance (InternshipTutorLink::isTerminated()) is read-only for all three roles at
 * once, and that is settled here rather than in each of the five wizard actions: « on ne demande
 * plus rien » has to hold for the two portals and for a direct URL alike, and a gate repeated five
 * times is a gate forgotten once.
 *
 * Termination is deliberately a read-only rule and not a closed "open" gate: the is*Open() methods
 * say whose turn the chain reached, and terminating freezes that reading rather than rewinding it.
 * A period whose wizard was reachable stays reachable, showing what was filled in with nothing left
 * to submit - the staff chips on the suivi screen are that consultation path. What answers "is
 * anyone still being asked" is not this class but the lists: a terminated alternance leaves the
 * tutor's own portal, the student's « Mon alternance » and every pending count, all of which query
 * on inactiveDate.
 */
class AlternancePeriodWizardService
{
    public function __construct(
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
    ) {
    }

    // The 3 per-role evaluations for one (tutorLink, period) - feeds the wizards' shared
    // role-progress strip so every role's chip shows its real signed/pending state, whichever
    // role's wizard is being viewed (each wizard action otherwise only loads its own role's
    // entity).
    /** @return array{tutorEvaluation: ?InternshipTutorEvaluation, studentEvaluation: ?InternshipStudentEvaluation, supervisorEvaluation: ?InternshipSupervisorEvaluation} */
    public function evaluationsFor(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): array
    {
        $student = $tutorLink->getStudent();

        return [
            'tutorEvaluation' => $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period),
            'studentEvaluation' => null !== $student ? $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null,
            'supervisorEvaluation' => $this->supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period),
        ];
    }

    // Tuteur may start once the centre representative has signed the engagement (§3 "Mise à
    // disposition ... ouvre les périodes d'évaluation").
    public function arePeriodsOpen(InternshipTutorLink $tutorLink): bool
    {
        $engagement = $this->engagementRepository->findOneForTutorLink($tutorLink);

        return null !== $engagement && null !== $engagement->getSignedCenterAt();
    }

    public function isPeriodClosed(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        return $this->supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period)?->isClosed() ?? false;
    }

    // Alternant may start once the tutor has signed their own step 4.
    public function isStudentStepOpen(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        return $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period)?->isSigned() ?? false;
    }

    // Chargé de suivi may start once the alternant has signed their own step 4 - the last
    // signature before the closure, the teaching team's step having been removed from the chain.
    public function isSupervisorStepOpen(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        $student = $tutorLink->getStudent();

        return null !== $student && ($this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }

    // True once the tuteur's own step 4 signature is recorded, OR the period is closed - either
    // way nothing more can be edited on their behalf.
    public function isTutorStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        if ($tutorLink->isTerminated() || $this->isPeriodClosed($tutorLink, $period)) {
            return true;
        }

        return $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period)?->isSigned() ?? false;
    }

    public function isStudentStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        if ($tutorLink->isTerminated() || $this->isPeriodClosed($tutorLink, $period)) {
            return true;
        }

        $student = $tutorLink->getStudent();

        return null !== $student && ($this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }

    // The chargé de suivi's counterpart to the two above - their wizard had no read-only rule of
    // its own, only "the period is closed", which is why this arrives with the termination gate.
    // Closing a period is the last thing the centre does, and a terminated alternance is not
    // closed on its way out: what is not signed stays unsigned.
    public function isSupervisorStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        return $tutorLink->isTerminated() || $this->isPeriodClosed($tutorLink, $period);
    }
}
