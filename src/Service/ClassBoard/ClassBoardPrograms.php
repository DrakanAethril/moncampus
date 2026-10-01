<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use App\Entity\Program;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;

/**
 * The classes a board may be linked to - the rule of the random draw (design/validated/
 * tableau-virtuel.md, §2): the classes one teaches, filtered by the test zone; whoever teaches
 * none (administration) keeps the full list, as in the Outils menu's class picker.
 */
final class ClassBoardPrograms
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    /** @return list<Program> */
    public function linkable(User $user): array
    {
        $programs = $this->programRepository->findAllForTeacher($user);
        if ([] === $programs && $this->accessChecker->isStaff()) {
            $programs = $this->programRepository->findActiveForNav($user);
        }

        return array_values(array_filter(
            $programs,
            fn (Program $program): bool => $this->accessChecker->isProgramTeacher($program),
        ));
    }

    public function find(User $user, ?int $programId): ?Program
    {
        if (null === $programId) {
            return null;
        }

        foreach ($this->linkable($user) as $program) {
            if ($program->getId() === $programId) {
                return $program;
            }
        }

        return null;
    }
}
