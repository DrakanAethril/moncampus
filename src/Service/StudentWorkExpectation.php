<?php

namespace App\Service;

use App\Entity\AssignmentExpectedProduction;
use App\Entity\AssignmentSubmission;

/**
 * One thing an assignment expects a student to hand in, and what they have handed in for it - a
 * line of the "Dépôts demandés" section of the consigne modal (design_handoff_travail_a_faire,
 * screen 3b).
 *
 * An assignment that spells out its expected productions has one of these per production, each
 * with its own deadline and its own "Déposer" button. One that does not still has exactly one,
 * production-less, standing for the assignment as a whole - so the screens never have to ask which
 * of the two shapes they are looking at.
 *
 * $dismissed is this expectation's own: "Ignorer" answers the line it was clicked on, and setting
 * one production aside leaves the deadlines that follow it standing.
 */
final readonly class StudentWorkExpectation
{
    public function __construct(
        public ?AssignmentExpectedProduction $production,
        public ?AssignmentSubmission $submission,
        public ?\DateTimeImmutable $dueDate,
        public bool $closed,
        public bool $dismissed = false,
    ) {
    }

    public function isSubmitted(): bool
    {
        return null !== $this->submission;
    }

    /** Nothing handed in and the deadline gone - what still makes an assignment late. */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return !$this->isSubmitted() && null !== $this->dueDate && $this->dueDate < $now;
    }

    /** Whether a deposit is still accepted: not closed, whether or not it is already late. */
    public function acceptsSubmission(): bool
    {
        return !$this->closed;
    }
}
