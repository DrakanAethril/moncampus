<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\QuizInstance;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Enum\AssignmentNature;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AssignmentViewRepository;
use App\Repository\AudioListenProgressRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SelfAssessmentRepository;
use App\Repository\VideoWatchProgressRepository;

/**
 * L'« Avancement » de la liste des travaux (design_handoff_creation_travail 2b) : une phrase par
 * travail, dont la forme dépend du type - « 21 rendus · 3 non rendus » pour un dépôt échu, « 18 / 24
 * ont répondu » pour un quiz, « lu par 12 / 19 » pour une lecture suivie, « 2 / 5 groupes ont
 * déposé » quand le dépôt est collectif.
 *
 * Tout se calcule en une passe sur toute la liste (un comptage groupé par type de preuve), et non
 * travail par travail : la page couvre plusieurs classes d'un coup.
 *
 * Un travail qui n'est pas encore visible n'a pas d'avancement à montrer - personne ne l'a vu. Il
 * rend à la place son état de publication, que la maquette affiche en pastille grise.
 */
class AssignmentProgressSummarizer
{
    public function __construct(
        private readonly AssignmentAudienceResolver $audienceResolver,
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly AssignmentViewRepository $viewRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
        private readonly SelfAssessmentRepository $selfAssessmentRepository,
        private readonly QuizAttemptRepository $quizAttemptRepository,
        private readonly AudioListenProgressRepository $listenProgressRepository,
        private readonly VideoWatchProgressRepository $watchProgressRepository,
    ) {
    }

    /**
     * @param list<Assignment> $assignments
     *
     * @return array<int, array{key: string, params: array<string, int|string>, alert: bool, muted: bool}>
     *                                                                                                    identifiant du travail => avancement à afficher
     */
    public function summarize(array $assignments, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $submissionCounts = $this->submissionRepository->countByAssignment($assignments);
        $submitterIds = $this->submissionRepository->findSubmitterIdsByAssignment($assignments);
        $viewCounts = $this->viewRepository->countByAssignment($assignments);
        $completionCounts = $this->completionRepository->countByAssignment($assignments);
        $selfAssessmentCounts = $this->selfAssessmentRepository->countByAssignment($assignments);
        $respondentCounts = $this->quizAttemptRepository->countRespondentsByInstance(array_values(array_filter(
            array_map(static fn (Assignment $assignment): ?QuizInstance => $assignment->getQuizInstance(), $assignments),
        )));

        $summaries = [];

        foreach ($assignments as $assignment) {
            $id = (int) $assignment->getId();

            if (!$assignment->isVisibleFor($now)) {
                $summaries[$id] = [
                    'key' => null === $assignment->getVisibleAt() ? 'assignmentProgressHiddenLabel' : 'assignmentProgressScheduledLabel',
                    'params' => ['%date%' => $assignment->getVisibleAt()?->format('d/m/Y') ?? ''],
                    'alert' => false,
                    'muted' => true,
                ];

                continue;
            }

            $audienceSize = \count($this->audienceResolver->resolveAudience($assignment));

            $summaries[$id] = match (true) {
                AssignmentNature::Quiz === $assignment->getNature() => [
                    'key' => 'assignmentProgressAnsweredLabel',
                    'params' => ['%done%' => $respondentCounts[(int) $assignment->getQuizInstance()?->getId()] ?? 0, '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
                null !== $assignment->getAudioRecording() => [
                    'key' => 'assignmentProgressListenedLabel',
                    'params' => ['%done%' => $this->countFullListeners($assignment), '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
                null !== $assignment->getVideoResource() => [
                    'key' => 'assignmentProgressWatchedLabel',
                    'params' => ['%done%' => $this->countFullWatchers($assignment), '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
                AssignmentNature::SelfAssessment === $assignment->getNature() => [
                    'key' => 'assignmentProgressSelfAssessedLabel',
                    'params' => ['%done%' => $selfAssessmentCounts[$id] ?? 0, '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
                AssignmentNature::ToRead === $assignment->getNature() && $assignment->isReadTrackingEnabled() => [
                    'key' => 'assignmentProgressReadLabel',
                    'params' => ['%done%' => $viewCounts[$id] ?? 0, '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
                $assignment->expectsSubmission() => $this->submissionProgress(
                    $assignment,
                    $submissionCounts[$id] ?? 0,
                    $submitterIds[$id] ?? [],
                    $audienceSize,
                    $now,
                ),
                default => [
                    'key' => 'assignmentProgressDeclaredDoneLabel',
                    'params' => ['%done%' => $completionCounts[$id] ?? 0, '%total%' => $audienceSize],
                    'alert' => false,
                    'muted' => false,
                ],
            };
        }

        return $summaries;
    }

    /**
     * How many students have listened to everything that is theirs - the Listening assignment's own
     * completion rule - the same one App\Service\AudioListenTracker applies to one student.
     *
     * One query per listening assignment rather than one grouped count for the whole list: in
     * individualised mode "everything" is not the same set of files from one student to the next, so
     * there is no single total to compare a COUNT against. Listening assignments are a handful in a
     * teacher's list, which is what makes that affordable.
     */
    /**
     * The same count on the video side: a student is counted once every file of the set is watched
     * through, which is the completion rule App\Service\VideoWatchTracker applies to one student.
     */
    private function countFullWatchers(Assignment $assignment): int
    {
        $resource = $assignment->getVideoResource();

        if (null === $resource || $resource->getFiles()->isEmpty()) {
            return 0;
        }

        $progressByStudentId = $this->watchProgressRepository->findByStudentAndFileForResource($resource);
        $done = 0;

        foreach ($this->audienceResolver->resolveAudience($assignment) as $student) {
            foreach ($resource->getFiles() as $file) {
                if (!($progressByStudentId[(int) $student->getId()][(int) $file->getId()] ?? null)?->isComplete()) {
                    continue 2;
                }
            }

            ++$done;
        }

        return $done;
    }

    private function countFullListeners(Assignment $assignment): int
    {
        $recording = $assignment->getAudioRecording();

        if (null === $recording) {
            return 0;
        }

        $progressByStudentId = $this->listenProgressRepository->findByStudentAndFileForRecording($recording);
        $done = 0;

        foreach ($this->audienceResolver->resolveAudience($assignment) as $student) {
            $files = $recording->getFilesFor($student);

            if ([] === $files) {
                continue;
            }

            foreach ($files as $file) {
                if (!($progressByStudentId[(int) $student->getId()][(int) $file->getId()] ?? null)?->isComplete()) {
                    continue 2;
                }
            }

            ++$done;
        }

        return $done;
    }

    /**
     * Un dépôt se raconte différemment avant et après l'échéance : « 5 / 14 ont déposé » tant qu'il
     * reste du temps, « 21 rendus · 3 non rendus » une fois l'heure passée - c'est alors le manque
     * qui compte, et il se lit en rouge.
     *
     * @param list<int> $submitterIds
     *
     * @return array{key: string, params: array<string, int|string>, alert: bool, muted: bool}
     */
    private function submissionProgress(Assignment $assignment, int $submitted, array $submitterIds, int $audienceSize, \DateTimeImmutable $now): array
    {
        if (AssignmentAudienceType::GroupBatch === $assignment->getAudienceType()) {
            $groups = $this->audienceResolver->resolveGroups($assignment);
            $submittedGroups = \count(array_filter(
                $groups,
                static fn (array $members): bool => [] !== array_intersect(
                    array_map(static fn (User $member): int => (int) $member->getId(), $members),
                    $submitterIds,
                ),
            ));

            return [
                'key' => 'assignmentProgressGroupsSubmittedLabel',
                'params' => ['%done%' => $submittedGroups, '%total%' => \count($groups)],
                'alert' => false,
                'muted' => false,
            ];
        }

        if ($assignment->getDueDate() > $now) {
            return [
                'key' => 'assignmentProgressSubmittedLabel',
                'params' => ['%done%' => $submitted, '%total%' => $audienceSize],
                'alert' => false,
                'muted' => false,
            ];
        }

        $missing = max(0, $audienceSize - $submitted);

        return [
            'key' => 'assignmentProgressSubmittedAndMissingLabel',
            'params' => ['%done%' => $submitted, '%missing%' => $missing],
            'alert' => $missing > 0,
            'muted' => false,
        ];
    }
}
