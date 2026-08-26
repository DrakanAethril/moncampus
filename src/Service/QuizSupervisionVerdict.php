<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the rule says about one question. Never a score, never a boolean "cheated" - a verdict is a
 * flag and the reasons for it, both readable out loud in front of a student.
 */
final readonly class QuizSupervisionVerdict
{
    /**
     * @param list<string> $reasons translation keys naming why this question is worth a look
     * @param list<int>    $absencesMs kept even when nothing is flagged: the timeline shows them
     */
    public function __construct(
        public int $position,
        public bool $flagged,
        public array $reasons,
        public array $absencesMs,
        public ?int $elapsedMs,
        public int $displayCount,
        public ?bool $isCorrect,
    ) {
    }

    /** Whether this row has anything at all to show - a flag, an absence, or a re-display. */
    public function hasSomethingToShow(): bool
    {
        return $this->flagged || [] !== $this->absencesMs || $this->displayCount > 1;
    }
}
