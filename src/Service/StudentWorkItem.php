<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Enum\StudentWorkState;

/**
 * One line of the student's "Travail à faire" screen: an assignment, where it stands for that
 * student, and the deadline the line is filed under.
 *
 * $dueDate is the next deadline that still matters - the earliest one not yet answered while
 * something is outstanding, the last one once everything is in. It is what the date separators
 * group on and what the row prints the hour of, and it differs from the assignment's own due date
 * as soon as an expected production carries a deadline of its own.
 */
final readonly class StudentWorkItem
{
    /** @param list<StudentWorkExpectation> $expectations */
    public function __construct(
        public Assignment $assignment,
        public StudentWorkState $state,
        public \DateTimeImmutable $dueDate,
        public array $expectations,
        public ?\DateTimeImmutable $finishedAt = null,
    ) {
    }

    public function isDismissed(): bool
    {
        return StudentWorkState::Dismissed === $this->state;
    }

    /** The day the date separator is drawn for - one separator per day, however many rows follow. */
    public function dueDay(): string
    {
        return $this->dueDate->format('Y-m-d');
    }

    /** How many deposits the assignment asks for, once it spells them out. */
    public function productionCount(): int
    {
        return \count(array_filter($this->expectations, static fn (StudentWorkExpectation $e): bool => null !== $e->production));
    }

    /**
     * The expectation a plain "Déposer" on the row answers: the first one still open and not yet
     * handed in. Null once there is nothing left to hand in.
     */
    public function nextOpenExpectation(): ?StudentWorkExpectation
    {
        foreach ($this->expectations as $expectation) {
            if (!$expectation->isSubmitted() && $expectation->acceptsSubmission()) {
                return $expectation;
            }
        }

        return null;
    }
}
