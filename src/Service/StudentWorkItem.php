<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Enum\StudentWorkState;

/**
 * Where one assignment stands for one student: the assignment, its state, and the deadline it is
 * filed under.
 *
 * $dueDate is the next deadline that still matters - the earliest one not yet answered while
 * something is outstanding, the last one once everything is in. It differs from the assignment's
 * own due date as soon as an expected production carries a deadline of its own, and it is what
 * "Derniers travaux" and the history read.
 *
 * The "Travail à faire" list itself is drawn from App\Service\StudentWorkRow instead, one line per
 * deadline rather than one per assignment.
 */
final readonly class StudentWorkItem
{
    /**
     * @param list<StudentWorkExpectation> $expectations
     * @param list<string>                 $lockReasons  what an access condition still asks for, empty while nothing does
     */
    public function __construct(
        public Assignment $assignment,
        public StudentWorkState $state,
        public \DateTimeImmutable $dueDate,
        public array $expectations,
        public ?\DateTimeImmutable $finishedAt = null,
        public array $lockReasons = [],
    ) {
    }

    /**
     * The same line, held by an access condition. The state is left untouched on purpose: a locked
     * work is still due, still late, and still filed under its own day - what changes is that its
     * actions are replaced by the sentence saying how to open it.
     *
     * @param list<string> $reasons
     */
    public function lockedBy(array $reasons): self
    {
        return new self($this->assignment, $this->state, $this->dueDate, $this->expectations, $this->finishedAt, $reasons);
    }

    public function isLocked(): bool
    {
        return [] !== $this->lockReasons;
    }
}
