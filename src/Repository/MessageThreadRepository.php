<?php

namespace App\Repository;

use App\Entity\MessageThread;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageThread>
 */
class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    // Backs App\Service\MessageThreadRecipientSyncer's inbox-wide catch-up: every Program/
    // AllStudents/AllTeachers/AllStaff-audience thread (Manual is deliberately excluded, see
    // MessageThread's docblock) where this user doesn't have a MessageThreadRecipient row yet - a
    // candidate list the syncer then re-checks one by one against
    // AudienceResolver::isVisibleTo() before actually granting a row, since audience_type alone
    // doesn't prove they're still/now eligible (e.g. a Program audience thread targeting Programs
    // this user was never part of).
    /** @return list<MessageThread> */
    public function findDynamicAudienceThreadsMissingRecipientFor(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.audienceType IN (:types)')
            ->setParameter('types', [MessageAudienceType::Program, MessageAudienceType::AllStudents, MessageAudienceType::AllTeachers, MessageAudienceType::AllStaff])
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\MessageThreadRecipient r WHERE r.thread = t AND r.user = :user)')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
