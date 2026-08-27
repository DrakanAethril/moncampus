<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\EvaluationPeriod;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\QuizMode;
use App\Service\StudentWorkBoard;
use App\Service\StudentWorkItem;

/**
 * The work family, read off App\Service\StudentWorkBoard and **not recomputed**.
 *
 * The board already owns the whole state rule - Submitted, Late, Done, Missed, Todo, Dismissed, one
 * line per expected production rather than per assignment - and it is what the student's own screen
 * shows. A second implementation here would eventually disagree with it, and the student would be
 * looking at two screens saying different things about the same deposit.
 *
 * Three kinds of deadline are counted, and only three (§5.2): an expected production, an évaluation
 * quiz, a self-assessment. A listening, a video, a work simply declared done are deliberately not
 * échéances of the game - paying them would pay a trace rather than a proof (§4, decision 4).
 *
 * **A deadline enters the denominator once it has elapsed, or as soon as it is answered.** Counting
 * deadlines still ahead would make the rate fall every time a teacher schedules something, and the
 * index is read live, all period long.
 */
final class GameWorkReader
{
    public function __construct(
        private readonly StudentWorkBoard $board,
        private readonly GameSignalReader $signals,
        private readonly GameRuleResolver $rules,
    ) {
    }

    /**
     * @return list<GameWorkDeadline>
     */
    public function deadlines(User $student, Program $program, EvaluationPeriod $period, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $start = $period->getStartDate();
        $end = $period->getEndDate();

        if (null === $start || null === $end) {
            return [];
        }

        $onTime = $this->rules->valueOf($program, $period, GameRuleCatalog::WORK_ON_TIME)?->possibleValue() ?? 30;
        $quizValue = $this->rules->valueOf($program, $period, GameRuleCatalog::WORK_QUIZ)?->possibleValue() ?? 20;
        $selfValue = $this->rules->valueOf($program, $period, GameRuleCatalog::WORK_SELF_ASSESSMENT)?->possibleValue() ?? 10;

        $attempts = $this->attemptsByInstance($student, $start, $end);
        $selfAssessments = $this->selfAssessmentsByDeadline($student, $program, $start, $end);

        $deadlines = [];

        foreach ($this->board->build($student, $now) as $item) {
            if ($item->assignment->getProgram()?->getId() !== $program->getId()) {
                continue;
            }

            foreach ($this->submissionDeadlines($item, $period, $now, $onTime) as $deadline) {
                $deadlines[] = $deadline;
            }

            $quiz = $this->quizDeadline($item, $period, $now, $quizValue, $attempts);
            if (null !== $quiz) {
                $deadlines[] = $quiz;
            }

            $self = $this->selfAssessmentDeadline($item, $period, $now, $selfValue, $selfAssessments);
            if (null !== $self) {
                $deadlines[] = $self;
            }
        }

        return $deadlines;
    }

    /**
     * One deadline per expected production, which is the board's own granularity.
     *
     * @return list<GameWorkDeadline>
     */
    private function submissionDeadlines(StudentWorkItem $item, EvaluationPeriod $period, \DateTimeImmutable $now, int $maxPoints): array
    {
        $deadlines = [];

        foreach ($item->expectations as $expectation) {
            $dueDate = $expectation->dueDate ?? $item->assignment->getDueDate();

            if (null === $dueDate || !$period->contains($dueDate)) {
                continue;
            }

            $submission = $expectation->submission;

            if (null === $submission) {
                // Nothing handed in. It counts in the denominator once the window has shut, and not
                // a minute earlier - it is still doable until then.
                if ($dueDate <= $now) {
                    $deadlines[] = new GameWorkDeadline($maxPoints, $dueDate);
                }

                continue;
            }

            $submittedAt = $submission->getSubmittedAt() ?? $dueDate;
            $late = $submittedAt > $dueDate;

            $deadlines[] = new GameWorkDeadline(
                $maxPoints,
                $dueDate,
                $late ? GameRuleCatalog::WORK_LATE : GameRuleCatalog::WORK_ON_TIME,
                'AssignmentSubmission',
                $submission->getId(),
                $submittedAt,
            );
        }

        return $deadlines;
    }

    /**
     * An évaluation quiz, worth its points for having been sat - **the score pays nothing** (§5.2).
     *
     * Paying performance would turn the game into a second academic ranking, worse than the first;
     * quality enters the index exactly once, through the class council's mention.
     *
     * @param array<int, array{id: int, at: \DateTimeImmutable}> $attempts keyed by instance id
     */
    private function quizDeadline(StudentWorkItem $item, EvaluationPeriod $period, \DateTimeImmutable $now, int $maxPoints, array $attempts): ?GameWorkDeadline
    {
        $instance = $item->assignment->getQuizInstance();

        if (null === $instance || QuizMode::Evaluation !== $instance->getMode()) {
            return null;
        }

        $dueDate = $item->assignment->getDueDate();

        if (null === $dueDate || !$period->contains($dueDate)) {
            return null;
        }

        $attempt = $attempts[(int) $instance->getId()] ?? null;

        if (null === $attempt) {
            return $dueDate <= $now ? new GameWorkDeadline($maxPoints, $dueDate) : null;
        }

        return new GameWorkDeadline(
            $maxPoints,
            $dueDate,
            GameRuleCatalog::WORK_QUIZ,
            'QuizAttempt',
            $attempt['id'],
            $attempt['at'],
        );
    }

    /**
     * A self-assessment, paid when it was filled **before** its deadline.
     *
     * @param array<int, array{id: int, at: \DateTimeImmutable}> $validations keyed by assignment id
     */
    private function selfAssessmentDeadline(StudentWorkItem $item, EvaluationPeriod $period, \DateTimeImmutable $now, int $maxPoints, array $validations): ?GameWorkDeadline
    {
        if (!$item->assignment->getNature()->expectsSelfAssessment()) {
            return null;
        }

        $dueDate = $item->assignment->getDueDate();

        if (null === $dueDate || !$period->contains($dueDate)) {
            return null;
        }

        $validation = $validations[(int) $item->assignment->getId()] ?? null;

        if (null === $validation || $validation['at'] > $dueDate) {
            return $dueDate <= $now ? new GameWorkDeadline($maxPoints, $dueDate) : null;
        }

        return new GameWorkDeadline(
            $maxPoints,
            $dueDate,
            GameRuleCatalog::WORK_SELF_ASSESSMENT,
            'SelfAssessment',
            $validation['id'],
            $validation['at'],
        );
    }

    /**
     * The first concluded attempt on each évaluation instance - the one that answers the deadline.
     *
     * @return array<int, array{id: int, at: \DateTimeImmutable}>
     */
    private function attemptsByInstance(User $student, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $byInstance = [];

        foreach ($this->signals->quizAttempts($student, $from, $to) as $attempt) {
            if (QuizMode::Evaluation->value !== $attempt['mode']) {
                continue;
            }

            $byInstance[$attempt['instance']] ??= ['id' => $attempt['id'], 'at' => $attempt['submittedAt']];
        }

        return $byInstance;
    }

    /**
     * @return array<int, array{id: int, at: \DateTimeImmutable}>
     */
    private function selfAssessmentsByDeadline(User $student, Program $program, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $byAssignment = [];

        foreach ($this->signals->selfAssessments($student, $program, $from, $to) as $row) {
            $byAssignment[$row['assignment']] = ['id' => $row['id'], 'at' => $row['at']];
        }

        return $byAssignment;
    }
}
