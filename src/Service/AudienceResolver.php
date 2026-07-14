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
            MessageAudienceType::ProgramStudents => $target->getProgram()?->getStudents()->toArray() ?? [],
            MessageAudienceType::ProgramTeachers => $target->getProgram()?->getTeachers()->toArray() ?? [],
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
}
