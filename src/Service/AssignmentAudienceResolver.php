<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\User;
use App\Enum\AssignmentAudienceType;
use App\Repository\ProgramStudentOptionRepository;
use App\Repository\UserRepository;

/** Resolves an Assignment's audience to the actual list of eligible students. */
class AssignmentAudienceResolver
{
    public function __construct(
        private readonly ProgramStudentOptionRepository $programStudentOptionRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /** @return list<User> */
    public function resolveAudience(Assignment $assignment): array
    {
        return match ($assignment->getAudienceType()) {
            AssignmentAudienceType::Program => $assignment->getProgram()->getStudents()->toArray(),
            AssignmentAudienceType::Option => !$assignment->getOptions()->isEmpty()
                ? $this->programStudentOptionRepository->findStudentsForProgramAndOptions($assignment->getProgram(), $assignment->getOptions())
                : [],
            AssignmentAudienceType::Manual => $assignment->getManualRecipients()->toArray(),
            AssignmentAudienceType::GroupBatch => $this->resolveGroupBatchAudience($assignment),
            null => [],
        };
    }

    /**
     * The audience of an assignment targeted by groups is the union of the batch's members - a student
     * who has left the class since the batch was saved is no longer part of it, hence going through the
     * repository rather than simply decoding the frozen identifiers.
     *
     * @return list<User>
     */
    private function resolveGroupBatchAudience(Assignment $assignment): array
    {
        $batch = $assignment->getGroupBatch();

        if (null === $batch) {
            return [];
        }

        $studentIds = array_values(array_unique(array_merge(...$batch->getGroups() ?: [[]])));

        return [] === $studentIds ? [] : $this->userRepository->findByIdsForProgram($assignment->getProgram(), $studentIds);
    }

    /**
     * The groups of the targeted batch, each resolved into its present members - what the mockup shows
     * as summary chips, and what the « n / m groupes ont déposé » progress is counted on.
     *
     * @return list<list<User>>
     */
    public function resolveGroups(Assignment $assignment): array
    {
        $batch = $assignment->getGroupBatch();

        if (null === $batch || AssignmentAudienceType::GroupBatch !== $assignment->getAudienceType()) {
            return [];
        }

        $membersById = [];
        foreach ($this->resolveGroupBatchAudience($assignment) as $student) {
            $membersById[$student->getId()] = $student;
        }

        return array_map(
            static fn (array $ids): array => array_values(array_filter(array_map(
                static fn (int $id): ?User => $membersById[$id] ?? null,
                $ids,
            ))),
            $batch->getGroups(),
        );
    }

    public function isInAudience(Assignment $assignment, User $user): bool
    {
        return \in_array($user, $this->resolveAudience($assignment), true);
    }
}
