<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPathQuizAttempt;
use App\Entity\LearningPathStep;
use App\Enum\LearningPathStepState;

/**
 * One step of a path as one person sees it: where it stands, and the traces that say so.
 */
final readonly class LearningPathStepView
{
    /**
     * @param list<LearningPathQuizAttempt> $attempts this person's attempts on the step, oldest first
     */
    public function __construct(
        public LearningPathStep $step,
        public int $number,
        public LearningPathStepState $state,
        public ?LearningPathStep $lockedBy,
        public ?\DateTimeImmutable $openedAt,
        public array $attempts,
        public ?int $bestScore,
    ) {
    }

    public function isValidated(): bool
    {
        return $this->step->isQuiz() && LearningPathStepState::Done === $this->state;
    }

    /** @return list<LearningPathQuizAttempt> */
    public function finishedAttempts(): array
    {
        return array_values(array_filter($this->attempts, static fn (LearningPathQuizAttempt $attempt): bool => $attempt->isFinished()));
    }

    /** When the best score first reached the threshold of the day. */
    public function validatedAt(): ?\DateTimeImmutable
    {
        if (!$this->isValidated()) {
            return null;
        }

        foreach ($this->finishedAttempts() as $attempt) {
            if (($attempt->getScorePercent() ?? 0) >= $this->step->getPassPercent()) {
                return $attempt->getFinishedAt();
            }
        }

        return null;
    }
}
