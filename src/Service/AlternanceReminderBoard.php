<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Enum\ReminderBatchStep;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipReminderBatchItemRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\ProgramRepository;
use App\Repository\SchoolYearRepository;
use App\Service\ReminderBatch\ReminderBatchTarget;
use App\Service\ReminderBatch\ReminderWaiting;

/**
 * Who is left to chase, and for which stage of the livret - the single answer behind the reminder
 * batches (what a batch takes, the count under its form) and the staff dashboard banner that sends
 * people to them.
 *
 * The banner used to ask its own question, two SQL counts over the bilans running today, and got a
 * different answer from the screen it points at. One board, read by both, is what makes the banner
 * mean « there is something to send ».
 *
 * An alternance waits on at most one person at a time: the tutor or the alternant of the
 * engagement, or of the first bilan not closed - the order is AlternancePeriodStatusResolver's,
 * read here through an index of the formation's signatures rather than four queries per alternance.
 * The centre's signature and the supervisor's closing are not here: no reminder chases them.
 *
 * A bilan is named by its rank among the formation's active bilans (design/validated/
 * ufa-reminder-batches.md, Q2), which is what lets one batch chase « the 2nd bilan » of several
 * formations whose bilans have different names and dates.
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
        private InternshipReminderBatchItemRepository $batchItemRepository,
        private AlternancePeriodStatusResolver $statusResolver,
    ) {
    }

    public function schoolYear(): ?SchoolYear
    {
        return $this->schoolYearRepository->findCurrentOrMostRecent();
    }

    /**
     * The formations in alternance of the year, as a batch may take them. « Toutes les formations »
     * means the real ones: a real account reaches the test formations too, by naming them - a
     * rehearsal never mails the real companies by accident, nor the other way round.
     *
     * @return list<Program>
     */
    public function programs(?User $viewer, bool $includeTest = true): array
    {
        $schoolYear = $this->schoolYear();
        if (null === $schoolYear) {
            return [];
        }

        $programs = $this->programRepository->findAlternanceForSchoolYear($schoolYear, false, $viewer);
        if ($includeTest || true === $viewer?->isTestUser()) {
            return $programs;
        }

        return array_values(array_filter($programs, static fn (Program $program): bool => !$program->isTestProgram()));
    }

    /**
     * @param list<Program> $programs
     *
     * @return list<ReminderWaiting>
     */
    public function waiting(array $programs, ?ReminderBatchTarget $target = null): array
    {
        $waiting = [];
        foreach ($programs as $program) {
            foreach ($this->waitingInProgram($program) as $entry) {
                if (null === $target || $entry->target->key() === $target->key()) {
                    $waiting[] = $entry;
                }
            }
        }

        return $waiting;
    }

    // What the dashboard banner asks: is somebody waiting whom no running or paused batch holds
    // already? Once the work is launched the banner stops calling for it. Deliberately the same walk
    // as the batches rather than a cheaper count - a banner right nine times out of ten sends
    // someone, the tenth, to a form that says there is nobody.
    public function hasPendingReminders(?User $viewer): bool
    {
        return $this->unqueued($viewer)['total'] > 0;
    }

    /**
     * The people waiting in the real formations whom no running or paused batch holds, and the
     * target most of them wait on - what « Créer une relance » opens the form on.
     *
     * @return array{total: int, busiest: ReminderBatchTarget|null}
     */
    public function unqueued(?User $viewer): array
    {
        $queued = $this->batchItemRepository->findQueuedKeys();
        $counts = [];
        $targets = [];
        foreach ($this->waiting($this->programs($viewer, false)) as $entry) {
            if (isset($queued[InternshipReminderBatchItemRepository::queuedKey((int) $entry->tutorLink->getId(), $entry->target)])) {
                continue;
            }
            $key = $entry->target->key();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $targets[$key] = $entry->target;
        }

        if ([] === $counts) {
            return ['total' => 0, 'busiest' => null];
        }

        $total = array_sum($counts);
        arsort($counts);

        return ['total' => $total, 'busiest' => $targets[array_key_first($counts)]];
    }

    /**
     * @return list<ReminderWaiting>
     */
    private function waitingInProgram(Program $program): array
    {
        $tutorLinks = $this->tutorLinkRepository->findAllActiveForProgram($program);
        if ([] === $tutorLinks) {
            return [];
        }

        $periods = $this->evaluationPeriodRepository->findAllActiveForProgram($program);
        $signatures = $this->engagementRepository->findSignatureStatesForProgram($program);

        $rankByPeriodId = [];
        foreach ($periods as $position => $period) {
            $rankByPeriodId[(int) $period->getId()] = $position + 1;
        }

        // Read only when a bilan can be open at all: five queries for the whole formation, where
        // the resolver alone would ask four per alternance and per bilan.
        $index = null;

        $waiting = [];
        foreach ($tutorLinks as $tutorLink) {
            if ($tutorLink->isTerminated()) {
                continue;
            }

            $signed = $signatures[(int) $tutorLink->getId()] ?? ['tutor' => false, 'student' => false, 'center' => false];
            $engagementStep = AlternancePeriodStatusResolver::engagementStep($signed['tutor'], $signed['student'], $signed['center']);
            if (null !== $engagementStep) {
                $step = ReminderBatchStep::fromStatusStep($engagementStep);
                if (null !== $step) {
                    $waiting[] = new ReminderWaiting($program, $tutorLink, new ReminderBatchTarget($step, null), null);
                }
                continue;
            }

            if ([] === $periods) {
                continue;
            }

            $index ??= new AlternanceSubmissionIndex(
                array_map(static fn (array $state): true => true, array_filter($signatures, static fn (array $state): bool => $state['tutor'] && $state['student'] && $state['center'])),
                $this->tutorEvaluationRepository->findSignedPairsForProgram($program),
                $this->studentEvaluationRepository->findSignedPairsForProgram($program),
                $this->supervisorEvaluationRepository->findClosedPairsForProgram($program),
            );

            foreach ($this->statusResolver->resolveStepsForAllPeriods($tutorLink, $periods, $index) as $periodId => $status) {
                $step = ReminderBatchStep::fromStatusStep($status->step);
                if (null !== $step && null !== $status->period) {
                    $waiting[] = new ReminderWaiting($program, $tutorLink, new ReminderBatchTarget($step, $rankByPeriodId[$periodId]), $status->period);
                    break;
                }
            }
        }

        return $waiting;
    }
}
