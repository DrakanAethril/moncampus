<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\AccessConditionType;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AudioRecordingFileRepository;
use App\Repository\GradeRepository;
use App\Repository\LibraryResourceInstanceViewRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SeanceInstanceRepository;
use App\Repository\VideoResourceFileRepository;

/**
 * Turns a screenful of conditions into one StudentAccessFacts: one query per *type* of leaf, never
 * one per leaf. This is the whole reason the decision was split from its data - the "Travail à
 * faire" table would otherwise walk straight back into the N+1 already documented on audience
 * resolution.
 *
 * A type nobody asked about costs nothing: every loader below returns immediately on an empty list
 * of ids, so a list of conditions naming only séances runs exactly one query.
 */
class AccessConditionFactsLoader
{
    public function __construct(
        private readonly QuizAttemptRepository $attemptRepository,
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
        private readonly AudioRecordingFileRepository $audioFileRepository,
        private readonly VideoResourceFileRepository $videoFileRepository,
        private readonly LibraryResourceInstanceViewRepository $viewRepository,
        private readonly SeanceInstanceRepository $seanceRepository,
        private readonly GradeRepository $gradeRepository,
    ) {
    }

    /**
     * @param list<AccessConditionTree> $trees every condition the screen is about to decide
     */
    public function load(array $trees, User $student, ?\DateTimeImmutable $now = null): StudentAccessFacts
    {
        $ids = $this->targetIdsByType($trees);

        $quizIds = $ids[AccessConditionType::QuizScore->value] ?? [];
        $assignmentIds = $ids[AccessConditionType::AssignmentDone->value] ?? [];
        $recordingIds = $ids[AccessConditionType::AudioListened->value] ?? [];
        $videoIds = $ids[AccessConditionType::VideoWatched->value] ?? [];
        $resourceIds = $ids[AccessConditionType::ResourceViewed->value] ?? [];
        $seanceIds = $ids[AccessConditionType::SeancePassed->value] ?? [];
        $evaluationIds = $ids[AccessConditionType::GradeValue->value] ?? [];

        $done = array_merge(
            $this->submissionRepository->findSubmittedAssignmentIdsForStudent($assignmentIds, $student),
            $this->completionRepository->findDoneAssignmentIdsForStudent($assignmentIds, $student),
        );

        [$seanceStartDates, $seanceEndDates] = $this->seanceDates($seanceIds);

        return new StudentAccessFacts(
            $now ?? new \DateTimeImmutable(),
            $this->attemptRepository->findBestPercentByInstanceIdForStudent($quizIds, $student),
            array_fill_keys($done, true),
            $this->audioFileRepository->findLowestPercentByRecordingIdForStudent($recordingIds, $student),
            $this->videoFileRepository->findLowestPercentByResourceIdForStudent($videoIds, $student),
            array_fill_keys($this->viewRepository->findOpenedResourceIdsForStudent($resourceIds, $student), true),
            $seanceStartDates,
            $seanceEndDates,
            // The groups a student belongs to are already on the row the firewall loaded, and there
            // are a handful of them: reading the association is cheaper than a query of our own.
            array_fill_keys(array_map(
                static fn ($group): int => (int) $group->getId(),
                $student->getManualGroups()->toArray(),
            ), true),
            $this->gradeRepository->findValueByEvaluationIdForStudent($evaluationIds, $student),
        );
    }

    /**
     * @param list<int> $seanceIds
     *
     * @return array{array<int, ?\DateTimeImmutable>, array<int, ?\DateTimeImmutable>}
     */
    private function seanceDates(array $seanceIds): array
    {
        $starts = [];
        $ends = [];

        foreach ($this->seanceRepository->findWithSlotByIds($seanceIds) as $id => $seance) {
            // Resolved through the slot, exactly as the cahier de texte resolves "après la séance"
            // (LessonLogVisibility::AfterSession). One meaning of it in the application, not two.
            $slot = $seance->getLessonSession();
            $starts[$id] = $slot?->getStartAt();
            $ends[$id] = $slot?->getEndAt();
        }

        return [$starts, $ends];
    }

    /**
     * @param list<AccessConditionTree> $trees
     *
     * @return array<string, list<int>>
     */
    private function targetIdsByType(array $trees): array
    {
        $ids = [];
        foreach ($trees as $tree) {
            foreach ($tree->targetIdsByType() as $type => $some) {
                $ids[$type] = array_merge($ids[$type] ?? [], $some);
            }
        }

        return array_map(static fn (array $some): array => array_values(array_unique($some)), $ids);
    }
}
