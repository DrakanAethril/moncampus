<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;

/**
 * Who is left to chase, bilan by bilan - the single answer behind both the grouped relances screen
 * (/ufa/reminders) and the staff dashboard banner that sends people to it.
 *
 * The banner used to ask its own question, two SQL counts over the bilans running today, and got a
 * different answer from the screen it points at: a bilan that closed last month had late
 * evaluations the banner never mentioned, and an alternance stuck on its team signature raised a
 * banner the screen had nothing to show for. One board, read by both, is what makes the banner mean
 * "there is something on that screen".
 *
 * Only bilans with at least one pending tutor/alternant are returned, in the order the screen
 * offers them: by formation, then by bilan start date. An empty list is the banner's "nothing to
 * say" and the screen's "nothing to relance".
 *
 * @phpstan-type ReminderRow array{tutorLink: InternshipTutorLink, status: AlternanceStepStatus}
 * @phpstan-type ReminderBilan array{period: InternshipEvaluationPeriod, rows: list<ReminderRow>}
 */
final readonly class AlternanceReminderBoard
{
    public function __construct(
        private SchoolYearRepository $schoolYearRepository,
        private ProgramRepository $programRepository,
        private InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private InternshipTutorLinkRepository $tutorLinkRepository,
        private InternshipLivretEngagementRepository $engagementRepository,
        private InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
        private AlternancePeriodStatusResolver $statusResolver,
    ) {
    }

    /**
     * @return list<ReminderBilan>
     */
    public function build(?User $viewer): array
    {
        return $this->collect($viewer, false);
    }

    // What the dashboard banner asks. Deliberately the same walk rather than a cheaper count: a
    // banner that is right nine times out of ten is worse than no banner, because the tenth sends
    // someone to a screen that says there is nothing to do. It stops at the first bilan with
    // somebody in it, so the common case reads one formation, not all of them.
    public function hasPendingReminders(?User $viewer): bool
    {
        return [] !== $this->collect($viewer, true);
    }

    /**
     * @return list<ReminderBilan>
     */
    private function collect(?User $viewer, bool $stopAtFirstBilan): array
    {
        $schoolYear = $this->schoolYearRepository->findCurrentOrMostRecent();
        if (null === $schoolYear) {
            return [];
        }

        $bilans = [];
        foreach ($this->programRepository->findAlternanceForSchoolYear($schoolYear, false, $viewer) as $program) {
            $periods = $this->evaluationPeriodRepository->findAllActiveForProgram($program);
            $tutorLinks = $this->tutorLinkRepository->findAllActiveForProgram($program);
            if ([] === $periods || [] === $tutorLinks) {
                continue;
            }

            // Six queries for a whole formation, whatever its size - the resolver would otherwise
            // ask four per alternance and per bilan, which is a few hundred on a dashboard.
            $index = new AlternanceSubmissionIndex(
                $this->engagementRepository->findFullySignedTutorLinkIdsForProgram($program),
                $this->tutorEvaluationRepository->findSignedPairsForProgram($program),
                $this->studentEvaluationRepository->findSignedPairsForProgram($program),
                $this->supervisorEvaluationRepository->findClosedPairsForProgram($program),
            );

            $rowsByPeriod = [];
            foreach ($tutorLinks as $tutorLink) {
                foreach ($this->statusResolver->resolveStepsForAllPeriods($tutorLink, $periods, $index) as $periodId => $status) {
                    if (\in_array($status->step, [AlternanceStepStatus::STEP_TUTOR, AlternanceStepStatus::STEP_STUDENT], true)) {
                        $rowsByPeriod[$periodId][] = ['tutorLink' => $tutorLink, 'status' => $status];
                    }
                }
            }

            foreach ($periods as $period) {
                $rows = $rowsByPeriod[(int) $period->getId()] ?? [];
                if ([] !== $rows) {
                    $bilans[] = ['period' => $period, 'rows' => $rows];

                    if ($stopAtFirstBilan) {
                        return $bilans;
                    }
                }
            }
        }

        return $bilans;
    }
}
