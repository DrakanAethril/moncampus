<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Program;
use App\Repository\InternshipEvaluationPeriodRepository;
use App\Repository\InternshipLivretEngagementRepository;
use App\Repository\InternshipStudentEvaluationRepository;
use App\Repository\InternshipSupervisorEvaluationRepository;
use App\Repository\InternshipTutorEvaluationRepository;
use App\Repository\InternshipTutorLinkRepository;

/**
 * Where every live alternance of a set of formations stands, as an AlternanceListStep key - what
 * the UFA list's « Étape » filter keeps.
 *
 * The decision is AlternancePeriodStatusResolver's, the very one the list's badges read: the
 * engagement signatures in order, then the first bilan not closed. Only the reading changes - one
 * AlternanceSubmissionIndex per formation, a handful of queries, where the resolver alone would ask
 * four per alternance and per bilan (AlternanceReminderBoard reads the same way).
 */
final readonly class AlternanceListSteps
{
    public function __construct(
        private InternshipTutorLinkRepository $tutorLinkRepository,
        private InternshipEvaluationPeriodRepository $evaluationPeriodRepository,
        private InternshipLivretEngagementRepository $engagementRepository,
        private InternshipTutorEvaluationRepository $tutorEvaluationRepository,
        private InternshipStudentEvaluationRepository $studentEvaluationRepository,
        private InternshipSupervisorEvaluationRepository $supervisorEvaluationRepository,
        private AlternancePeriodStatusResolver $statusResolver,
    ) {
    }

    /**
     * The live alternances of these formations standing at $step, by id. A terminated alternance
     * stands at no step.
     *
     * @param list<Program> $programs
     *
     * @return list<int>
     */
    public function tutorLinkIdsAt(array $programs, AlternanceListStep $step): array
    {
        $ids = [];
        foreach ($programs as $program) {
            foreach ($this->keysForProgram($program) as $tutorLinkId => $key) {
                if ($key === $step->key()) {
                    $ids[] = $tutorLinkId;
                }
            }
        }

        return $ids;
    }

    /**
     * @return array<int, string> tutor link id => AlternanceListStep key
     */
    public function keysForProgram(Program $program): array
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

        // Read only once some alternance has its engagement complete: before that no bilan is open.
        $index = null;

        $keys = [];
        foreach ($tutorLinks as $tutorLink) {
            if ($tutorLink->isTerminated()) {
                continue;
            }
            $tutorLinkId = (int) $tutorLink->getId();

            $signed = $signatures[$tutorLinkId] ?? ['tutor' => false, 'student' => false, 'center' => false];
            $engagementStep = AlternancePeriodStatusResolver::engagementStep($signed['tutor'], $signed['student'], $signed['center']);
            if (null !== $engagementStep) {
                $keys[$tutorLinkId] = (string) AlternanceListStep::of($engagementStep)?->key();
                continue;
            }

            $index ??= new AlternanceSubmissionIndex(
                array_map(static fn (array $state): true => true, array_filter($signatures, static fn (array $state): bool => $state['tutor'] && $state['student'] && $state['center'])),
                $this->tutorEvaluationRepository->findSignedPairsForProgram($program),
                $this->studentEvaluationRepository->findSignedPairsForProgram($program),
                $this->supervisorEvaluationRepository->findClosedPairsForProgram($program),
            );

            $keys[$tutorLinkId] = AlternanceListStep::CLOSED;
            foreach ($this->statusResolver->resolveStepsForAllPeriods($tutorLink, $periods, $index) as $periodId => $status) {
                if (AlternanceStepStatus::STEP_CLOSED !== $status->step) {
                    $keys[$tutorLinkId] = (string) AlternanceListStep::of($status->step, $rankByPeriodId[$periodId] ?? null)?->key();
                    break;
                }
            }
        }

        return $keys;
    }
}
