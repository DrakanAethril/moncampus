<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Enum\StudentWorkState;

/**
 * One line of the "Travail à faire" list (design_handoff_travail_a_faire, screen 3c).
 *
 * A line is a deadline, not an assignment: an assignment spelling out three expected productions
 * dated three different days is read on three lines, each filed under its own day, so that nothing
 * scheduled ahead is hidden behind an earlier deadline. An assignment asking for a single deposit -
 * or for no deposit at all, like a quiz or a listening - still reads on exactly one line, its
 * $expectation being respectively its lone expectation or null.
 *
 * The state carried here is the line's own, which is finer than the assignment's: the first
 * production can be late while the next is still comfortably ahead.
 */
final readonly class StudentWorkRow
{
    public function __construct(
        public StudentWorkItem $item,
        public ?StudentWorkExpectation $expectation,
        public StudentWorkState $state,
        public \DateTimeImmutable $dueDate,
    ) {
    }

    public function assignment(): Assignment
    {
        return $this->item->assignment;
    }

    public function isDismissed(): bool
    {
        return StudentWorkState::Dismissed === $this->state;
    }

    /** The day the date separator is drawn for - one separator per day, however many lines follow. */
    public function dueDay(): string
    {
        return $this->dueDate->format('Y-m-d');
    }

    /** Whether this very line still takes a deposit: something is expected, and nothing answers it yet. */
    public function acceptsSubmission(): bool
    {
        return null !== $this->expectation
            && !$this->expectation->isSubmitted()
            && $this->expectation->acceptsSubmission();
    }
}
