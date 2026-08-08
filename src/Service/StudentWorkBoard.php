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
        private readonly AudioListenTracker $listenTracker,
    ) {
    }

    /**
     * Every assignment a student can see, with its state - the caller sorts them into the screen's
     * groups. An assignment set aside as a whole while already late is dropped here and not merely
     * flagged: "un travail en retard ignoré disparaît de la liste", including from the history. A
     * work whose deadlines were set aside one at a time is dropped one line at a time instead, in
     * rows() - the assignment only leaves once nothing of it is left standing.
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
        $dismissed = $this->dismissalRepository->findDismissedProductionIds($assignments, $student);
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
            // A dismissal naming no production stands for the assignment as a whole; the others
            // each answer one deadline.
            $dismissedHere = $dismissed[$id] ?? [];
            $dismissedProductionIds = array_flip(array_filter($dismissedHere, static fn (?int $productionId): bool => null !== $productionId));

            $expectations = $this->expectationsOf($assignment, $submissions[$id] ?? [], $now, \in_array(null, $dismissedHere, true), $dismissedProductionIds);
            $finishedAt = $this->finishedAt($assignment, $student, $expectations, $attempts, $doneDates, $validationDates);
            $item = $this->itemOf($assignment, $expectations, $finishedAt, \in_array(null, $dismissedHere, true), $now);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The same material read one deadline at a time rather than one assignment at a time: a work
     * asking for several dated productions yields one line per production, filed under its own day,
     * so that none of them stays hidden behind the earliest.
     *
     * Only what is still ahead of the student is expanded: an assignment already filed under
     * "Derniers travaux" - finished, or left unhandled once every window shut - is read there, not
     * here. A production handed in whose deadline has passed drops out the same way, for the same
     * reason it does at assignment level: it is behind.
     *
     * Both the "Travail à faire" list and the dashboard's "Travail à réaliser" card are drawn from
     * this, which is what keeps them from ever announcing different things.
     *
     * @param list<StudentWorkItem> $items
     *
     * @return list<StudentWorkRow>
     */
    public function rows(array $items, \DateTimeImmutable $now): array
    {
        $listed = [StudentWorkState::Late, StudentWorkState::Todo, StudentWorkState::Submitted, StudentWorkState::Dismissed];

        $rows = [];
        foreach ($items as $item) {
            if (!\in_array($item->state, $listed, true)) {
                continue;
            }

            // Nothing to hand in (a quiz, a listening, a self-assessment, a work simply to declare
            // done): one line, the assignment's own.
            if ([] === $item->expectations) {
                $rows[] = new StudentWorkRow($item, null, $item->state, $item->dueDate);

                continue;
            }

            foreach ($item->expectations as $expectation) {
                $dueDate = $expectation->dueDate ?? $item->assignment->getDueDate();

                if ($expectation->isSubmitted() && $dueDate < $now) {
                    continue;
                }

                $state = $this->rowState($expectation, $dueDate, $now);

                // A deadline set aside once it was already late disappears, exactly as a whole
                // assignment does - the deadlines it was set aside among stay listed.
                if (StudentWorkState::Dismissed === $state && $dueDate < $now) {
                    continue;
                }

                $rows[] = new StudentWorkRow($item, $expectation, $state, $dueDate);
            }
        }

        return $rows;
    }

    /**
     * Where one deadline stands, which is finer than where its assignment stands: the first
     * production can be late while the next is still ahead. A deposit whose window shut with
     * nothing in it stays late - it is the assignment as a whole that moves to "Derniers travaux",
     * once no deadline of its own is left open.
     */
    private function rowState(StudentWorkExpectation $expectation, \DateTimeImmutable $dueDate, \DateTimeImmutable $now): StudentWorkState
    {
        // The line's own dismissal, not the assignment's: "Ignorer" answers the deadline it was
        // clicked on and leaves its neighbours alone.
        if ($expectation->dismissed) {
            return StudentWorkState::Dismissed;
        }

        if ($expectation->isSubmitted()) {
            return StudentWorkState::Submitted;
        }

        return $dueDate < $now ? StudentWorkState::Late : StudentWorkState::Todo;
    }

    /**
     * What the assignment asks for and what answers it. An assignment spelling out its expected
     * productions gets one expectation per production; one that does not gets a single
     * production-less expectation, so both shapes read the same downstream.
     *
     * @param list<AssignmentSubmission> $submissions
     * @param array<int, mixed>          $dismissedProductionIds set of expected production ids set aside, keyed by id
     *
     * @return list<StudentWorkExpectation>
     */
    private function expectationsOf(Assignment $assignment, array $submissions, \DateTimeImmutable $now, bool $wholeDismissed, array $dismissedProductionIds): array
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

            // The lone line stands for the assignment, so the assignment's own dismissal is its.
            return [new StudentWorkExpectation(null, $global, $due, $this->isClosed($assignment, $due, $now), $wholeDismissed)];
        }

        return array_values(array_map(function (AssignmentExpectedProduction $production) use ($assignment, $byProductionId, $global, $now, $wholeDismissed, $dismissedProductionIds): StudentWorkExpectation {
            $due = $production->getEffectiveDueDate();

            // A deposit made before the assignment spelled out its productions answers the first of
            // them: it was handed in for this assignment, and losing it would read as "non rendu".
            $submission = $byProductionId[$production->getId()] ?? (0 === $production->getPosition() ? $global : null);

            // A dismissal predating the productions was taken on the assignment as a whole and is
            // honoured as such - it is what the student set aside, back when there was one line.
            $dismissed = $wholeDismissed || isset($dismissedProductionIds[$production->getId()]);

            return new StudentWorkExpectation($production, $submission, $due, $this->isClosed($assignment, $due, $now), $dismissed);
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
     * validated estimate for a self-assessment, a fully listened recording for a listening, the
     * student's word for anything else.
     *
     * @param list<StudentWorkExpectation>     $expectations
     * @param array<int, list<QuizAttempt>>    $attempts
     * @param array<int, \DateTimeImmutable>   $doneDates
     * @param array<int, \DateTimeImmutable>   $validationDates
     */
    private function finishedAt(Assignment $assignment, User $student, array $expectations, array $attempts, array $doneDates, array $validationDates): ?\DateTimeImmutable
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

        // "Le travail n'est considéré comme effectué pour un étudiant que lorsqu'il a écouté
        // l'intégralité de ses fichiers": the common ones and their own, each at 100%. The listen
        // tracking is the proof, there is nothing to declare.
        if (null !== $assignment->getAudioRecording()) {
            return $this->listenTracker->completedAt($assignment->getAudioRecording(), $student);
        }

        return $doneDates[$assignment->getId()] ?? null;
    }

    /**
     * The state of one assignment, and the deadline its row is filed under. Returns null for the
     * one case the screen drops entirely: an assignment set aside once it was already late.
     *
     * $wholeDismissed is the assignment's own dismissal - the one taken on a line standing for the
     * assignment itself. An assignment spelling out productions is only set aside once every
     * deadline it still owes has been, one by one: while a single one stands, the work stands.
     *
     * @param list<StudentWorkExpectation> $expectations
     */
    private function itemOf(Assignment $assignment, array $expectations, ?\DateTimeImmutable $finishedAt, bool $wholeDismissed, \DateTimeImmutable $now): ?StudentWorkItem
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

        $dismissed = [] === $expectations
            ? $wholeDismissed
            : [] !== $outstanding && [] === array_filter($outstanding, static fn (StudentWorkExpectation $e): bool => !$e->dismissed);

        $late = $dueDate < $now;

        if ($dismissed) {
            // Set aside as a whole while already late: the work disappears, list and history alike.
            // A work read one deadline at a time is not dropped here even so - rows() takes its
            // overdue lines away one by one, which leaves the deadlines still ahead where they are,
            // greyed out and restorable, instead of taking the whole work down with the first one.
            return $late && [] === $expectations
                ? null
                : new StudentWorkItem($assignment, StudentWorkState::Dismissed, $dueDate, $expectations);
        }

        return new StudentWorkItem($assignment, $late ? StudentWorkState::Late : StudentWorkState::Todo, $dueDate, $expectations);
    }
}
