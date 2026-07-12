<?php

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Repository\ProgramStudentOptionRepository;

/** Resolves an Assignment's audience to the actual list of eligible students. */
class AssignmentAudienceResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $programStudentOptionRepository,
    ) {
    }

    /** @return list<User> */
    public function resolveAudience(Assignment $assignment): array
    {
        return match ($assignment->getAudienceType()) {
            AssignmentAudienceType::Program => $assignment->getProgram()->getStudents()->toArray(),
            AssignmentAudienceType::Option => null !== $assignment->getOption()
                ? $this->programStudentOptionRepository->findStudentsForProgramAndOption($assignment->getProgram(), $assignment->getOption())
                : [],
            AssignmentAudienceType::Manual => $assignment->getManualRecipients()->toArray(),
            null => [],
        };
    }

    public function isInAudience(Assignment $assignment, User $user): bool
    {
        return \in_array($user, $this->resolveAudience($assignment), true);
    }
}
