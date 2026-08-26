<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the rule says about one whole attempt: a verdict per question, how many are worth a look,
 * and the separate reading of a paper that was probably known in advance.
 */
final readonly class QuizSupervisionReport
{
    /**
     * @param list<QuizSupervisionVerdict> $verdicts        one per question, in presentation order
     * @param int                          $flaggedCount    what classes the attempt - never a score
     * @param int                          $absenceCount    every absence, flagged or not
     * @param bool                         $suspiciouslyFast everything right and very fast, hard
     *                                                       questions included: not cheating in the
     *                                                       moment, a paper likely known in advance.
     *                                                       Deliberately outside $flaggedCount - it
     *                                                       questions the paper, not the student.
     */
    public function __construct(
        public array $verdicts,
        public int $flaggedCount,
        public int $absenceCount,
        public bool $suspiciouslyFast,
    ) {
    }

    public function verdictAt(int $position): QuizSupervisionVerdict
    {
        foreach ($this->verdicts as $verdict) {
            if ($verdict->position === $position) {
                return $verdict;
            }
        }

        // A position nothing was assessed for: an empty verdict rather than a null the callers
        // would each have to remember to guard.
        return new QuizSupervisionVerdict($position, false, [], [], null, 0, null);
    }

    public function hasAnythingToReport(): bool
    {
        return $this->flaggedCount > 0 || $this->absenceCount > 0 || $this->suspiciouslyFast;
    }

    /** The rows the timeline opens on - the others are folded away by default. */
    public function noteworthyVerdicts(): array
    {
        return array_values(array_filter($this->verdicts, static fn (QuizSupervisionVerdict $v): bool => $v->hasSomethingToShow()));
    }
}
