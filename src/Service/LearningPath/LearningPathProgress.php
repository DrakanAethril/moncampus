<?php

declare(strict_types=1);

namespace App\Service\LearningPath;

use App\Entity\LearningPathEnrollment;
use App\Enum\LearningPathStepState;

/**
 * A path read for one person: every step with its state, and the figures the screens print - the
 * person's own plan and the author's follow-up read the very same object, so an « avancement »
 * cannot be computed two ways.
 */
final readonly class LearningPathProgress
{
    /**
     * @param list<LearningPathStepView> $steps
     */
    public function __construct(
        public ?LearningPathEnrollment $enrollment,
        public array $steps,
        public bool $completed,
    ) {
    }

    /** The steps that count: an unavailable one is neither done nor to do. */
    public function totalCount(): int
    {
        return \count(array_filter($this->steps, static fn (LearningPathStepView $view): bool => LearningPathStepState::Unavailable !== $view->state));
    }

    public function doneCount(): int
    {
        return \count(array_filter($this->steps, static fn (LearningPathStepView $view): bool => LearningPathStepState::Done === $view->state));
    }

    public function percent(): int
    {
        return 0 === $this->totalCount() ? 0 : (int) round(100 * $this->doneCount() / $this->totalCount());
    }

    public function quizCount(): int
    {
        return \count(array_filter($this->steps, static fn (LearningPathStepView $view): bool => $view->step->isQuiz() && LearningPathStepState::Unavailable !== $view->state));
    }

    public function validatedQuizCount(): int
    {
        return \count(array_filter($this->steps, static fn (LearningPathStepView $view): bool => $view->isValidated()));
    }

    /** Where the person goes next: the first step that is reachable and not done. */
    public function next(): ?LearningPathStepView
    {
        foreach ($this->steps as $view) {
            if (LearningPathStepState::Open === $view->state) {
                return $view;
            }
        }

        return null;
    }

    public function viewOf(int $number): ?LearningPathStepView
    {
        return $this->steps[$number - 1] ?? null;
    }

    public function after(LearningPathStepView $view): ?LearningPathStepView
    {
        for ($number = $view->number + 1; $number <= \count($this->steps); ++$number) {
            $next = $this->viewOf($number);
            if (null !== $next && LearningPathStepState::Unavailable !== $next->state) {
                return $next;
            }
        }

        return null;
    }
}
