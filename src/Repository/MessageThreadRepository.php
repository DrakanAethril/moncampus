<?php

declare(strict_types=1);

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

    // Backs App\Service\MessageThreadRecipientSyncer's inbox-wide catch-up: every thread whose
    // audience has at least one type other than Manual (a purely manual audience is deliberately
    // excluded, see MessageThread's docblock) where this user doesn't have a
    // MessageThreadRecipient row yet - a candidate list the syncer then re-checks one by one
    // against AudienceResolver::isVisibleTo() before actually granting a row, since the audience
    // types alone don't prove they're still/now eligible (e.g. a Program audience thread targeting
    // Programs this user was never part of).
    //
    // "Has something other than Manual" is asked as a string inequality because that is what the
    // simple_array column holds, and MessageAudienceType::sort() guarantees a set containing
    // Manual and nothing else serialises to exactly 'manual' - no permutation to enumerate. Rows
    // with no audience at all (NULL) fall through as candidates and are then rejected by
    // isVisibleTo(), which costs one extra check on data the validator does not allow to exist.
    /** @return list<MessageThread> */
    public function findDynamicAudienceThreadsMissingRecipientFor(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.audienceTypes IS NULL OR t.audienceTypes <> :manualOnly')
            ->setParameter('manualOnly', MessageAudienceType::Manual->value)
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\MessageThreadRecipient r WHERE r.thread = t AND r.user = :user)')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
