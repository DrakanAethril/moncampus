<?php

namespace App\Service;

use App\Entity\MessageThread;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\UserRepository;

/**
 * Resolves a MessageThread's audience to the actual list of recipients (sender excluded) - same
 * role as App\Service\AssignmentAudienceResolver, one level up in the Assignment-submission-box
 * feature.
 */
class MessageAudienceResolver
{
    private const string ROLE_EXTERNAL = 'ROLE_EXTERNAL';

    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
    }

    /** @return list<User> */
    public function resolveRecipients(MessageThread $thread): array
    {
        return match ($thread->getAudienceType()) {
            MessageAudienceType::ProgramStudents => $thread->getProgram()?->getStudents()->toArray() ?? [],
            MessageAudienceType::ProgramTeachers => $thread->getProgram()?->getTeachers()->toArray() ?? [],
            // ROLE_EXTERNAL is never a valid recipient, even for a school-wide staff broadcast -
            // see design/validated/internal-messaging.md.
            MessageAudienceType::SchoolWide => $this->userRepository->findActiveExcludingRole(self::ROLE_EXTERNAL, [$thread->getSender()->getId()]),
            MessageAudienceType::Manual => $thread->getManualRecipients()->toArray(),
            null => [],
        };
    }
}
