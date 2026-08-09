<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipStudentEvaluation;
use App\Entity\InternshipSupervisorEvaluation;
use App\Entity\InternshipTeamEvaluation;
use App\Entity\InternshipTutorEvaluation;
use App\Entity\InternshipTutorLink;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;

/**
 * Cross-role gating + read-only rules shared by every one of the 4 per-period guided journeys
 * (Tuteur/Alternant/Équipe pédagogique/Chargé de suivi) and both of their portals (staff
 * "on-behalf" in Ufa\PeriodWizardController, self-service in InternshipTutorEvaluationController/
 * ProgramInternshipEvaluationController) - see the feature's plan doc, §Phase 5, for why the
 * within-one-role step order (1→2→3→4) is deliberately NOT enforced here: decision #3 makes a
 * role's own steps 1-3 silent, freely-revisitable drafts, only the cross-role gates below and the
 * post-signature/post-closure locks matter.
 */
class AlternancePeriodWizardService
{
    public function __construct(
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
        private readonly InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
    ) {
    }

    // The 4 per-role evaluations for one (tutorLink, period) - feeds the wizards' shared
    // role-progress strip so every role's chip shows its real signed/pending state, whichever
    // role's wizard is being viewed (each wizard action otherwise only loads its own role's
    // entity).
    /** @return array{tutorEvaluation: ?InternshipTutorEvaluation, studentEvaluation: ?InternshipStudentEvaluation, teamEvaluation: ?InternshipTeamEvaluation, supervisorEvaluation: ?InternshipSupervisorEvaluation} */
    public function evaluationsFor(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): array
    {
        $student = $tutorLink->getStudent();

        return [
            'tutorEvaluation' => $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period),
            'studentEvaluation' => null !== $student ? $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null,
            'teamEvaluation' => null !== $student ? $this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null,
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

    // Équipe pédagogique may start once the alternant has signed their own step 4.
    public function isTeamStepOpen(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        $student = $tutorLink->getStudent();

        return null !== $student && ($this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }

    // Chargé de suivi may start once the équipe pédagogique has signed their own step 4.
    public function isSupervisorStepOpen(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        $student = $tutorLink->getStudent();

        return null !== $student && ($this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }

    // True once the tuteur's own step 4 signature is recorded, OR the period is closed - either
    // way nothing more can be edited on their behalf.
    public function isTutorStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        if ($this->isPeriodClosed($tutorLink, $period)) {
            return true;
        }

        return $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period)?->isSigned() ?? false;
    }

    public function isStudentStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        if ($this->isPeriodClosed($tutorLink, $period)) {
            return true;
        }

        $student = $tutorLink->getStudent();

        return null !== $student && ($this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }

    public function isTeamStepReadOnly(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): bool
    {
        if ($this->isPeriodClosed($tutorLink, $period)) {
            return true;
        }

        $student = $tutorLink->getStudent();

        return null !== $student && ($this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period)?->isSigned() ?? false);
    }
}
