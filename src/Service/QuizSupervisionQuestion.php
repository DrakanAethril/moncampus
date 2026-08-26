<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One question of a supervised attempt, reduced to what the rule actually reads.
 *
 * Primitives rather than entities on purpose: App\Service\QuizSupervisionAssessor is a function over
 * durations and booleans, and its test should not have to build a Program to say what it decides.
 * App\Service\QuizSupervisionReportBuilder is the one place that turns rows into these.
 */
final readonly class QuizSupervisionQuestion
{
    /**
     * @param int|null   $elapsedMs   time on screen, null when the question was never answered
     * @param int        $displayCount how many times it was served
     * @param bool|null  $isCorrect   null when unanswered
     * @param bool       $isHard      difficulty « difficile » - only read by the "known in advance" reading
     * @param list<int>  $absencesMs  the absences that fell inside this question's window
     * @param bool       $hasPaste    something was pasted into one of its fields
     */
    public function __construct(
        public int $position,
        public ?int $elapsedMs,
        public int $displayCount,
        public ?bool $isCorrect,
        public bool $isHard,
        public array $absencesMs,
        public bool $hasPaste,
    ) {
    }

    /** The longest absence that fell in this question's window, 0 when there was none. */
    public function longestAbsenceMs(): int
    {
        return [] === $this->absencesMs ? 0 : max($this->absencesMs);
    }
}
