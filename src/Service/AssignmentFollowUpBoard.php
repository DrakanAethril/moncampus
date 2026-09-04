<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\AssignmentFollowUpStatus;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\AudioListenProgressRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SelfAssessmentRepository;
use App\Repository\SurveyTargetRepository;
use App\Repository\VideoWatchProgressRepository;

/**
 * Where every student of an audience stands on one assignment - the teacher-facing twin of
 * App\Service\StudentWorkBoard, and it reads the same proofs in the same order for the same reason:
 * a rule applied on one side only is how two screens come to announce different things.
 *
 * It exists because the follow-up screen read deposits and nothing else. Six of the eleven natures
 * never produce one - a quiz, a survey, a listening, a watching, a self-assessment, and everything
 * settled by « marquer comme fait » - so twenty-two students who had answered a quiz were listed as
 * « Non rendu », under a progress line that correctly said « 22 / 23 ont répondu ». The screen was
 * only ever right about App\Enum\AssignmentNature::ToSubmit.
 *
 * Everything is read in batch, one query per kind of evidence for the whole audience: the class is
 * thirty rows and a query per row is what the deleted code did.
 */
class AssignmentFollowUpBoard
{
    public function __construct(
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly QuizAttemptRepository $attemptRepository,
        private readonly SelfAssessmentRepository $selfAssessmentRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
        private readonly SurveyTargetRepository $surveyTargetRepository,
        private readonly AudioListenProgressRepository $listenProgressRepository,
        private readonly VideoWatchProgressRepository $watchProgressRepository,
    ) {
    }

    /**
     * The branches are StudentWorkBoard::finishedAt()'s, in its order and on its conditions: the
     * evidence is what the assignment carries, never what its nature is called. An assignment
     * holding a quiz is read as a quiz even if somebody typed another nature on it.
     *
     * @param list<User> $audience
     *
     * @return list<AssignmentFollowUpRow>
     */
    public function rows(Assignment $assignment, array $audience): array
    {
        if ($assignment->expectsSubmission()) {
            return $this->submissionRows($assignment, $audience);
        }

        if (null !== $assignment->getQuizInstance()) {
            return $this->quizRows($assignment, $audience);
        }

        if ($assignment->getNature()->expectsSelfAssessment()) {
            $dates = [];
            foreach ($this->selfAssessmentRepository->findByStudentIdForAssignment($assignment) as $studentId => $selfAssessment) {
                $dates[$studentId] = $selfAssessment->getValidatedAt();
            }

            return $this->datedRows($assignment, $audience, $dates);
        }

        if (null !== $assignment->getAudioRecording()) {
            return $this->datedRows($assignment, $audience, $this->listenedDates($assignment, $audience));
        }

        if (null !== $assignment->getVideoResource()) {
            return $this->datedRows($assignment, $audience, $this->watchedDates($assignment, $audience));
        }

        if (null !== $assignment->getSurveyCampaign()) {
            $dates = [];
            foreach ($this->surveyTargetRepository->findAllFor($assignment->getSurveyCampaign()) as $target) {
                $user = $target->getUser();
                if (null !== $user) {
                    $dates[(int) $user->getId()] = $target->getRespondedAt();
                }
            }

            return $this->datedRows($assignment, $audience, $dates);
        }

        return $this->datedRows($assignment, $audience, $this->completionRepository->findDoneDatesByStudentIdForAssignment($assignment));
    }

    /**
     * The one branch that predates this class, kept gesture for gesture: a deposit is missing, on
     * time or late, and « en retard » is only ever said about a deposit - it is the only nature with
     * a window of its own to be late against.
     *
     * @param list<User> $audience
     *
     * @return list<AssignmentFollowUpRow>
     */
    private function submissionRows(Assignment $assignment, array $audience): array
    {
        $submissionsByStudentId = $this->submissionRepository->findAllByStudentIdForAssignment($assignment);

        return array_map(function (User $student) use ($assignment, $submissionsByStudentId): AssignmentFollowUpRow {
            $submissions = $submissionsByStudentId[(int) $student->getId()] ?? [];
            $submission = $submissions[0] ?? null;

            $status = match (true) {
                null === $submission => AssignmentFollowUpStatus::Pending,
                $assignment->isLate($submission->getSubmittedAt()) => AssignmentFollowUpStatus::Late,
                default => AssignmentFollowUpStatus::Done,
            };

            return new AssignmentFollowUpRow(
                $student,
                $status,
                AssignmentFollowUpStatus::Late === $status ? 'assignmentSubmissionStatusLateLabel' : $this->labelKeyOf($assignment, $status),
                $submission?->getSubmittedAt(),
                $submissions,
            );
        }, $audience);
    }

    /**
     * A quiz has three answers and not two, which is the whole reason this screen could not simply
     * be told « done or not »: a student who answered without reaching the teacher's threshold has
     * neither done the work nor stayed away from it. Reaching it once is enough - the same rule
     * StudentWorkBoard applies - so a weaker retry afterwards does not undo it.
     *
     * @param list<User> $audience
     *
     * @return list<AssignmentFollowUpRow>
     */
    private function quizRows(Assignment $assignment, array $audience): array
    {
        $instance = $assignment->getQuizInstance();
        \assert(null !== $instance);

        /** @var array<int, list<QuizAttempt>> $attemptsByStudentId */
        $attemptsByStudentId = [];
        foreach ($this->attemptRepository->findConcludedForInstance($instance) as $attempt) {
            $student = $attempt->getStudent();
            if (null !== $student) {
                $attemptsByStudentId[(int) $student->getId()][] = $attempt;
            }
        }

        return array_map(function (User $student) use ($assignment, $attemptsByStudentId): AssignmentFollowUpRow {
            $attempts = $attemptsByStudentId[(int) $student->getId()] ?? [];

            $retained = null;
            foreach ($attempts as $attempt) {
                if ($assignment->reachesMinimumScore($attempt->getScorePercent())) {
                    $retained = $attempt;

                    break;
                }
            }

            // Nothing reached the threshold: the last attempt is the one shown, because what the
            // teacher needs to see is that the student did sit the quiz.
            $retained ??= [] === $attempts ? null : $attempts[\count($attempts) - 1];

            $status = match (true) {
                null === $retained => AssignmentFollowUpStatus::Pending,
                $assignment->reachesMinimumScore($retained->getScorePercent()) => AssignmentFollowUpStatus::Done,
                default => AssignmentFollowUpStatus::Insufficient,
            };

            return new AssignmentFollowUpRow(
                $student,
                $status,
                AssignmentFollowUpStatus::Insufficient === $status ? 'assignmentFollowUpBelowThresholdLabel' : $this->labelKeyOf($assignment, $status),
                $retained?->getSubmittedAt(),
                [],
                $retained,
            );
        }, $audience);
    }

    /**
     * Every other nature, which all read the same way: a date or nothing. The proof differs - a
     * response, a validated estimate, a recording heard through, a declaration - but none of them
     * has an in-between state.
     *
     * @param list<User>                      $audience
     * @param array<int, ?\DateTimeImmutable> $doneAt   student id => when they settled it
     *
     * @return list<AssignmentFollowUpRow>
     */
    private function datedRows(Assignment $assignment, array $audience, array $doneAt): array
    {
        return array_map(function (User $student) use ($assignment, $doneAt): AssignmentFollowUpRow {
            $date = $doneAt[(int) $student->getId()] ?? null;
            $status = null === $date ? AssignmentFollowUpStatus::Pending : AssignmentFollowUpStatus::Done;

            return new AssignmentFollowUpRow($student, $status, $this->labelKeyOf($assignment, $status), $date);
        }, $audience);
    }

    /**
     * "Le travail n'est considéré comme effectué pour un étudiant que lorsqu'il a écouté
     * l'intégralité de ses fichiers" - App\Service\AudioListenTracker's rule, applied to a whole
     * audience off a single batch read. In individualised mode « ses fichiers » is not the same set
     * from one student to the next, which is why the loop asks the recording rather than counting.
     *
     * @param list<User> $audience
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function listenedDates(Assignment $assignment, array $audience): array
    {
        $recording = $assignment->getAudioRecording();
        \assert(null !== $recording);

        $progressByStudentId = $this->listenProgressRepository->findByStudentAndFileForRecording($recording);
        $dates = [];

        foreach ($audience as $student) {
            $files = $recording->getFilesFor($student);

            if ([] === $files) {
                continue;
            }

            $completed = [];
            foreach ($files as $file) {
                $progress = $progressByStudentId[(int) $student->getId()][(int) $file->getId()] ?? null;

                if (null === $progress || !$progress->isComplete()) {
                    continue 2;
                }

                $completed[] = $progress->getLastListenedAt();
            }

            $completed = array_values(array_filter($completed));
            if ([] !== $completed) {
                $dates[(int) $student->getId()] = max($completed);
            }
        }

        return $dates;
    }

    /**
     * The same rule on the video side (App\Service\VideoWatchTracker): every file of the set watched
     * through, and the date is the last of them.
     *
     * @param list<User> $audience
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function watchedDates(Assignment $assignment, array $audience): array
    {
        $resource = $assignment->getVideoResource();
        \assert(null !== $resource);

        if ($resource->getFiles()->isEmpty()) {
            return [];
        }

        $progressByStudentId = $this->watchProgressRepository->findByStudentAndFileForResource($resource);
        $dates = [];

        foreach ($audience as $student) {
            $completed = [];
            foreach ($resource->getFiles() as $file) {
                $progress = $progressByStudentId[(int) $student->getId()][(int) $file->getId()] ?? null;

                if (null === $progress || !$progress->isComplete()) {
                    continue 2;
                }

                $completed[] = $progress->getLastWatchedAt();
            }

            $completed = array_values(array_filter($completed));
            if ([] !== $completed) {
                $dates[(int) $student->getId()] = max($completed);
            }
        }

        return $dates;
    }

    private function labelKeyOf(Assignment $assignment, AssignmentFollowUpStatus $status): string
    {
        return AssignmentFollowUpStatus::Pending === $status
            ? $assignment->getNature()->followUpPendingLabelKey()
            : $assignment->getNature()->followUpDoneLabelKey();
    }
}
