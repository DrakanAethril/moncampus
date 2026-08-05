<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\TrainingApplicationRepository;

/**
 * Whether a student may write to a real company yet
 * (design_handoff_workflow_postulation, screen 8a).
 *
 * The lock is not a flag someone sets: it is the absence of a fully validated practice application.
 * Reading it that way means it can never fall out of step with the reviews - unlocking is what
 * happens when the fourth element is validated, not a second write that could be forgotten.
 */
class SchoolMailLockChecker
{
    public function __construct(
        private readonly TrainingApplicationRepository $applicationRepository,
    ) {
    }

    public function isUnlocked(User $student): bool
    {
        return $this->applicationRepository->hasValidatedApplication($student);
    }

    public function isLocked(User $student): bool
    {
        return !$this->isUnlocked($student);
    }
}
