<?php

namespace App\Service;

use App\Entity\MessageThread;
use App\Entity\MessageThreadRecipient;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Repository\MessageThreadRecipientRepository;
use App\Repository\MessageThreadRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Late-joiner catch-up for Program/AllStudents/AllTeachers/AllStaff-audience MessageThreads (see
 * MessageThread's docblock). Those audience types are meant to track live Program/role membership
 * the same way AgendaEvent/Announcement do (App\Security\Voter\AudienceTargetableVoter re-resolves
 * on every check) - but a thread's recipients are also fanned out into persistent
 * MessageThreadRecipient rows at send time for read-tracking, and that fan-out doesn't
 * automatically grow afterwards. This service is what closes that gap: called before a user reads
 * their inbox or a specific thread, it grants a row to anyone newly eligible since send time (just
 * joined a targeted Program, or a brand new account matching one of the broadcast roles) - Manual
 * stays untouched, its recipient list is a deliberate, fixed pick.
 */
class MessageThreadRecipientSyncer
{
    /** @var list<MessageAudienceType> */
    private const array DYNAMIC_TYPES = [MessageAudienceType::Program, MessageAudienceType::AllStudents, MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff];

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly MessageThreadRecipientRepository $recipientRepository,
        private readonly AudienceResolver $audienceResolver,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // Full catch-up for a user's inbox: scans every Program/SchoolWide thread missing a row for
    // them and grants one to whichever they're actually currently eligible for.
    public function syncForUser(User $user): void
    {
        $candidates = $this->threadRepository->findDynamicAudienceThreadsMissingRecipientFor($user);

        if ([] === $candidates) {
            return;
        }

        $granted = false;
        foreach ($candidates as $thread) {
            if ($this->audienceResolver->isVisibleTo($thread, $user)) {
                $this->entityManager->persist(new MessageThreadRecipient($thread, $user));
                $granted = true;
            }
        }

        if ($granted) {
            $this->entityManager->flush();
        }
    }

    // Targeted version for a direct thread view (a specific id in the URL) - checks/grants just
    // that one thread instead of scanning every candidate.
    public function syncForUserAndThread(User $user, MessageThread $thread): void
    {
        if (!\in_array($thread->getAudienceType(), self::DYNAMIC_TYPES, true)) {
            return;
        }

        if (null !== $this->recipientRepository->findOneForUserAndThread($user, $thread)) {
            return;
        }

        if ($this->audienceResolver->isVisibleTo($thread, $user)) {
            $this->entityManager->persist(new MessageThreadRecipient($thread, $user));
            $this->entityManager->flush();
        }
    }
}
