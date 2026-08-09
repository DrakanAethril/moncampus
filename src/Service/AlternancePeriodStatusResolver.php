<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTeamEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Single source of truth for "what step is this alternance/period at, and is it late" - replaces
 * the ad-hoc submitted|late|pending match-arms duplicated across Program\InternshipEvaluationStatusController's
 * staff status screens. Walks, in order: the 3 engagement signatures, then for each active
 * InternshipEvaluationPeriod (oldest first): tutor signedAt -> student signedAt -> team signedAt
 * -> supervisor closedAt. Lateness is always computed live against now() (isPast()-style, no
 * cron) - see the feature's plan doc, decision #2.
 */
class AlternancePeriodStatusResolver
{
    public function __construct(
        private readonly InternshipLivretEngagementRepository $engagementRepository,
        private readonly InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private readonly InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private readonly InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private readonly InternshipTeamEvaluationRepository $teamEvaluationRepository,
        private readonly InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The single "whose turn is it right now, for the whole alternance" gate - powers the
    // dashboard badge (33a/33b) and the suivi page's warning banner (34a/34b).
    public function resolveCurrentStep(InternshipTutorLink $tutorLink): AlternanceStepStatus
    {
        if (null !== $tutorLink->getInactiveDate()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_INACTIVE, false, null, null, null);
        }

        $engagementStatus = $this->resolveEngagementStep($tutorLink);
        if (null !== $engagementStatus) {
            return $engagementStatus;
        }

        $periods = $this->evaluationPeriodRepository->findAllActiveForProgram($tutorLink->getProgram());
        $lastClosedPeriod = null;

        foreach ($periods as $period) {
            $status = $this->resolvePeriodStep($tutorLink, $period);
            if (AlternanceStepStatus::STEP_CLOSED !== $status->step) {
                return $status;
            }
            $lastClosedPeriod = $period;
        }

        return new AlternanceStepStatus(AlternanceStepStatus::STEP_CLOSED, false, null, null, $lastClosedPeriod);
    }

    // Scoped to one already-known period - used for the per-period rows on 34a, where each
    // period shows its own 4-role progress strip regardless of whether earlier periods are done.
    public function resolveStepForPeriod(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): AlternanceStepStatus
    {
        if (null !== $tutorLink->getInactiveDate()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_INACTIVE, false, null, null, $period);
        }

        $engagementStatus = $this->resolveEngagementStep($tutorLink);
        if (null !== $engagementStatus) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_NOT_OPENED, false, null, null, $period);
        }

        // A period only opens once every earlier active period (by startDate) is closed - walk
        // up to (and including) this one so an out-of-order "check period 3" call still reports
        // "not_opened" if period 2 isn't closed yet, rather than evaluating period 3 in isolation.
        foreach ($this->evaluationPeriodRepository->findAllActiveForProgram($tutorLink->getProgram()) as $candidate) {
            $status = $this->resolvePeriodStep($tutorLink, $candidate);

            if ($candidate === $period) {
                return $status;
            }

            if (AlternanceStepStatus::STEP_CLOSED !== $status->step) {
                return new AlternanceStepStatus(AlternanceStepStatus::STEP_NOT_OPENED, false, null, null, $period);
            }
        }

        // $period wasn't found among the program's active periods (inactive/foreign period) -
        // defensive fallback, should not happen from any of this feature's own routes.
        return new AlternanceStepStatus(AlternanceStepStatus::STEP_NOT_OPENED, false, null, null, $period);
    }

    // Maps a resolved status to the exact 33a/33b pill text + Tabler light-badge class (see the
    // feature's plan doc, architecture call 10 - this app has no dedicated .cm-badge--* classes,
    // status pills elsewhere already use Tabler's bg-*-lt convention).
    /** @return array{label: string, class: string} */
    public function badgeFor(AlternanceStepStatus $status): array
    {
        return match (true) {
            AlternanceStepStatus::STEP_INACTIVE === $status->step => [
                'label' => $this->translator->trans('ufaAlternanceStatusInactiveBadgeLabel'),
                'class' => 'bg-secondary-lt',
            ],
            AlternanceStepStatus::STEP_NOT_OPENED === $status->step => [
                'label' => $this->translator->trans('ufaAlternanceStatusNotOpenedBadgeLabel'),
                'class' => 'bg-secondary-lt',
            ],
            AlternanceStepStatus::STEP_CLOSED === $status->step => [
                'label' => null !== $status->period
                    ? $this->translator->trans('ufaAlternanceStatusPeriodClosedBadgeLabel', ['%period%' => $status->period->getName()])
                    : $this->translator->trans('ufaAlternanceStatusEngagementCompleteBadgeLabel'),
                'class' => 'bg-green-lt',
            ],
            $status->isEngagementStep() => [
                'label' => $this->translator->trans('ufaAlternanceStatusEngagementBadgeLabel', ['%role%' => $this->badgeRoleLabel($status->step)]),
                'class' => $status->isLate ? 'bg-red-lt' : 'bg-blue-lt',
            ],
            $status->isLate => [
                'label' => $this->translator->trans('ufaAlternanceStatusPeriodLateBadgeLabel', [
                    '%period%' => $status->period?->getName(),
                    '%role%' => $this->badgeRoleLabel($status->step),
                ]),
                'class' => 'bg-red-lt',
            ],
            default => [
                'label' => $this->translator->trans('ufaAlternanceStatusPeriodRoleBadgeLabel', [
                    '%period%' => $status->period?->getName(),
                    '%role%' => $this->badgeRoleLabel($status->step),
                ]),
                'class' => 'bg-blue-lt',
            ],
        };
    }

    // Null once both signatures are set (engagement complete) so the caller falls through to
    // the per-period walk.
    private function resolveEngagementStep(InternshipTutorLink $tutorLink): ?AlternanceStepStatus
    {
        $engagement = $this->engagementRepository->findOneForTutorLink($tutorLink);

        if (null === $engagement || null === $engagement->getSignedTutorAt()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR, true, $tutorLink->getTutor(), null, null);
        }

        if (null === $engagement->getSignedStudentAt()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT, true, $tutorLink->getStudent(), null, null);
        }

        if (null === $engagement->getSignedCenterAt()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_ENGAGEMENT_CENTER, true, null, null, null);
        }

        return null;
    }

    private function resolvePeriodStep(InternshipTutorLink $tutorLink, InternshipEvaluationPeriod $period): AlternanceStepStatus
    {
        $isPast = $period->isPast();
        $dueDate = $period->getEndDate();

        $tutorEvaluation = $this->tutorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period);
        if (null === $tutorEvaluation || !$tutorEvaluation->isSigned()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_TUTOR, $isPast, $tutorLink->getTutor(), $dueDate, $period);
        }

        $student = $tutorLink->getStudent();
        $studentEvaluation = null !== $student ? $this->studentEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period) : null;
        if (null === $studentEvaluation || !$studentEvaluation->isSigned()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_STUDENT, $isPast, $student, $dueDate, $period);
        }

        $teamEvaluation = $this->teamEvaluationRepository->findOneForStudentAndEvaluationPeriod($student, $period);
        if (null === $teamEvaluation || !$teamEvaluation->isSigned()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_TEAM, $isPast, null, $dueDate, $period);
        }

        $supervisorEvaluation = $this->supervisorEvaluationRepository->findOneForTutorLinkAndEvaluationPeriod($tutorLink, $period);
        if (null === $supervisorEvaluation || !$supervisorEvaluation->isClosed()) {
            return new AlternanceStepStatus(AlternanceStepStatus::STEP_SUPERVISOR, $isPast, $tutorLink->getSupervisor(), $dueDate, $period);
        }

        return new AlternanceStepStatus(AlternanceStepStatus::STEP_CLOSED, false, null, null, $period);
    }

    private function badgeRoleLabel(string $step): string
    {
        return $this->translator->trans(match ($step) {
            AlternanceStepStatus::STEP_ENGAGEMENT_TUTOR, AlternanceStepStatus::STEP_TUTOR => 'ufaAlternanceBadgeRoleTutorLabel',
            AlternanceStepStatus::STEP_ENGAGEMENT_STUDENT, AlternanceStepStatus::STEP_STUDENT => 'ufaAlternanceBadgeRoleStudentLabel',
            AlternanceStepStatus::STEP_ENGAGEMENT_CENTER => 'ufaAlternanceBadgeRoleCenterLabel',
            AlternanceStepStatus::STEP_TEAM => 'ufaAlternanceBadgeRoleTeamLabel',
            AlternanceStepStatus::STEP_SUPERVISOR => 'ufaAlternanceBadgeRoleSupervisorLabel',
            default => 'ufaAlternanceBadgeRoleTutorLabel',
        });
    }
}
