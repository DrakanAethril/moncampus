<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\AssignmentExpectedProduction;
use App\Entity\AssignmentSubmission;
use App\Entity\QuizAttempt;
use App\Entity\User;
use App\Enum\StudentWorkState;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentDismissalRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\ProgramRepository;
use App\Repository\QuizAttemptRepository;
use App\Repository\SelfAssessmentRepository;

/**
 * Reads where every assignment stands for one student, in a handful of queries rather than one per
 * row - the material the "Travail à faire" screen and its history are drawn from
 * (design_handoff_travail_a_faire, screen 3c).
 *
 * Nothing here is persisted: a state is the reading of submissions, completions, quiz attempts and
 * dismissals against the clock, so it is always current and never has to be maintained.
 */
class StudentWorkBoard
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
        private readonly AssignmentDismissalRepository $dismissalRepository,
        private readonly QuizAttemptRepository $attemptRepository,
        private readonly SelfAssessmentRepository $selfAssessmentRepository,
        private readonly AssignmentAudienceResolver $audienceResolver,
    ) {
    }

    /**
     * Every assignment a student can see, with its state - the caller sorts them into the screen's
     * groups. A dismissed assignment that is already late is dropped here and not merely flagged:
     * "un travail en retard ignoré disparaît de la liste", including from the history.
     *
     * @return list<StudentWorkItem>
     */
    public function build(User $student, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $programs = $this->programRepository->findAllActiveForStudent($student);

        $assignments = array_values(array_filter(
            $this->assignmentRepository->findVisibleForPrograms($programs, $now),
            fn (Assignment $assignment): bool => $this->audienceResolver->isInAudience($assignment, $student),
        ));

        if ([] === $assignments) {
            return [];
        }

        $submissions = $this->submissionRepository->findByAssignmentIdForStudent($assignments, $student);
        $doneDates = $this->completionRepository->findDoneDates($assignments, $student);
        $dismissedIds = array_flip($this->dismissalRepository->findDismissedAssignmentIds($assignments, $student));
        $validationDates = $this->selfAssessmentRepository->findValidationDatesForStudent($assignments, $student);

        $instances = [];
        foreach ($assignments as $assignment) {
            if (null !== $assignment->getQuizInstance()) {
                $instances[$assignment->getQuizInstance()->getId()] = $assignment->getQuizInstance();
            }
        }
        $attempts = $this->attemptRepository->findConcludedByInstanceForStudent(array_values($instances), $student);

        $items = [];
        foreach ($assignments as $assignment) {
            $id = $assignment->getId();
            $expectations = $this->expectationsOf($assignment, $submissions[$id] ?? [], $now);
            $finishedAt = $this->finishedAt($assignment, $expectations, $attempts, $doneDates, $validationDates);
            $item = $this->itemOf($assignment, $expectations, $finishedAt, isset($dismissedIds[$id]), $now);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * What the assignment asks for and what answers it. An assignment spelling out its expected
     * productions gets one expectation per production; one that does not gets a single
     * production-less expectation, so both shapes read the same downstream.
     *
     * @param list<AssignmentSubmission> $submissions
     *
     * @return list<StudentWorkExpectation>
     */
    private function expectationsOf(Assignment $assignment, array $submissions, \DateTimeImmutable $now): array
    {
        if (!$assignment->expectsSubmission()) {
            return [];
        }

        $byProductionId = [];
        $global = null;
        foreach ($submissions as $submission) {
            $production = $submission->getExpectedProduction();
            null === $production ? $global = $submission : $byProductionId[$production->getId()] = $submission;
        }

        $productions = $assignment->getExpectedProductions()->toArray();

        if ([] === $productions) {
            $due = $assignment->getDueDate();

            return [new StudentWorkExpectation(null, $global, $due, $this->isClosed($assignment, $due, $now))];
        }

        return array_values(array_map(function (AssignmentExpectedProduction $production) use ($assignment, $byProductionId, $global, $now): StudentWorkExpectation {
            $due = $production->getEffectiveDueDate();

            // A deposit made before the assignment spelled out its productions answers the first of
            // them: it was handed in for this assignment, and losing it would read as "non rendu".
            $submission = $byProductionId[$production->getId()] ?? (0 === $production->getPosition() ? $global : null);

            return new StudentWorkExpectation($production, $submission, $due, $this->isClosed($assignment, $due, $now));
        }, $productions));
    }

    /**
     * Whether a deposit is still accepted. Allowing late submission keeps it open with no time
     * limit - the deposit is simply flagged as late in the teacher's follow-up.
     */
    private function isClosed(Assignment $assignment, ?\DateTimeImmutable $dueDate, \DateTimeImmutable $now): bool
    {
        return !$assignment->isLateSubmissionAllowed() && null !== $dueDate && $dueDate < $now;
    }

    /**
     * When the assignment was finished, or null while it is not. Each nature carries its own proof:
     * the deposits for a submission, a concluded attempt reaching the target for a quiz, a
     * validated estimate for a self-assessment, the student's word for anything else.
     *
     * @param list<StudentWorkExpectation>     $expectations
     * @param array<int, list<QuizAttempt>>    $attempts
     * @param array<int, \DateTimeImmutable>   $doneDates
     * @param array<int, \DateTimeImmutable>   $validationDates
     */
    private function finishedAt(Assignment $assignment, array $expectations, array $attempts, array $doneDates, array $validationDates): ?\DateTimeImmutable
    {
        if ($assignment->expectsSubmission()) {
            $dates = [];
            foreach ($expectations as $expectation) {
                if (!$expectation->isSubmitted()) {
                    return null;
                }

                $dates[] = $expectation->submission->getSubmittedAt();
            }

            return [] === $dates ? null : max($dates);
        }

        if (null !== $assignment->getQuizInstance()) {
            // Reaching the target once is enough: "le quiz n'est pas fait tant que l'étudiant n'a
            // pas atteint ce %", so a weaker retry afterwards does not undo it.
            foreach ($attempts[$assignment->getQuizInstance()->getId()] ?? [] as $attempt) {
                if ($assignment->reachesMinimumScore($attempt->getScorePercent())) {
                    return $attempt->getSubmittedAt();
                }
            }

            return null;
        }

        if ($assignment->getNature()->expectsSelfAssessment()) {
            return $validationDates[$assignment->getId()] ?? null;
        }

        return $doneDates[$assignment->getId()] ?? null;
    }

    /**
     * The state of one assignment, and the deadline its row is filed under. Returns null for the
     * one case the screen drops entirely: an assignment set aside once it was already late.
     *
     * @param list<StudentWorkExpectation> $expectations
     */
    private function itemOf(Assignment $assignment, array $expectations, ?\DateTimeImmutable $finishedAt, bool $dismissed, \DateTimeImmutable $now): ?StudentWorkItem
    {
        $dueDates = array_values(array_filter(array_map(
            static fn (StudentWorkExpectation $expectation): ?\DateTimeImmutable => $expectation->dueDate,
            $expectations,
        )));
        $dueDates[] = $assignment->getDueDate();

        $lastDue = max($dueDates);
        $outstanding = array_values(array_filter($expectations, static fn (StudentWorkExpectation $e): bool => !$e->isSubmitted()));

        // The deadline that still matters: the earliest one not yet answered, the last one once
        // everything is in. This is what the date separators group on.
        $dueDate = null !== $finishedAt || [] === $outstanding
            ? $lastDue
            : min(array_map(static fn (StudentWorkExpectation $e): \DateTimeImmutable => $e->dueDate ?? $assignment->getDueDate(), $outstanding));

        if (null !== $finishedAt) {
            return new StudentWorkItem($assignment, $lastDue >= $now ? StudentWorkState::Submitted : StudentWorkState::Done, $dueDate, $expectations, $finishedAt);
        }

        // Submission window shut with nothing handed in: the assignment leaves "En retard" for
        // "Derniers travaux", where it reads "Non rendu".
        $closedOut = $assignment->expectsSubmission()
            && [] !== $outstanding
            && [] === array_filter($outstanding, static fn (StudentWorkExpectation $e): bool => $e->acceptsSubmission());

        if ($closedOut) {
            return new StudentWorkItem($assignment, StudentWorkState::Missed, $dueDate, $expectations);
        }

        $late = $dueDate < $now;

        if ($dismissed) {
            return $late ? null : new StudentWorkItem($assignment, StudentWorkState::Dismissed, $dueDate, $expectations);
        }

        return new StudentWorkItem($assignment, $late ? StudentWorkState::Late : StudentWorkState::Todo, $dueDate, $expectations);
    }
}
