<?php

namespace App\Service;

use App\Entity\AudienceTargetable;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\UserRepository;

/**
 * Resolves any App\Entity\AudienceTargetable (MessageThread, Announcement, AgendaEvent) to the
 * actual list of Users it reaches - same role as App\Service\AssignmentAudienceResolver, one
 * level up in the Assignment-submission-box feature.
 */
class AudienceResolver
{
    private const string ROLE_EXTERNAL = 'ROLE_EXTERNAL';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    // $exclude drops one user from the resolved list regardless of audience type - used by
    // MessageController for the sender, who isn't a recipient of their own message. Announcement/
    // AgendaEvent have no such concept (their creator is meant to see their own post/event too),
    // so callers there simply omit it.
    /** @return list<User> */
    public function resolveRecipients(AudienceTargetable $target, ?User $exclude = null): array
    {
        $resolved = match ($target->getAudienceType()) {
            MessageAudienceType::Program => $this->resolveProgramAudience($target),
            // ROLE_EXTERNAL is never a valid recipient, even for a school-wide staff broadcast -
            // see design/validated/internal-messaging.md.
            MessageAudienceType::SchoolWide => $this->userRepository->findActiveExcludingRole(self::ROLE_EXTERNAL, null !== $exclude ? [$exclude->getId()] : []),
            MessageAudienceType::Manual => $target->getManualRecipients()->toArray(),
            null => [],
        };

        return null !== $exclude ? array_values(array_filter($resolved, static fn (User $user): bool => $user !== $exclude)) : $resolved;
    }

    public function isVisibleTo(AudienceTargetable $target, User $user): bool
    {
        return \in_array($user, $this->resolveRecipients($target), true);
    }

    // Union of students/teachers across every selected Program, deduplicated by id (a user
    // attached to more than one selected Program, e.g. a teacher across two of them, must only
    // appear once) - independent include flags mean "students only", "teachers only", or "both" of
    // each selected Program, see AudienceTargetable's docblock.
    /** @return list<User> */
    private function resolveProgramAudience(AudienceTargetable $target): array
    {
        $users = [];

        foreach ($target->getPrograms() as $program) {
            if ($target->isIncludeStudents()) {
                foreach ($program->getStudents() as $student) {
                    $users[$student->getId()] = $student;
                }
            }

            if ($target->isIncludeTeachers()) {
                foreach ($program->getTeachers() as $teacher) {
                    $users[$teacher->getId()] = $teacher;
                }
            }
        }

        return array_values($users);
    }
}
