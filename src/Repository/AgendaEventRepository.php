<?php

namespace App\Repository;

use App\Entity\AgendaEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgendaEvent>
 */
class AgendaEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgendaEvent::class);
    }

    // Unfiltered by audience - same "fine at this scale" convention as
    // AnnouncementRepository::findAllActive(), narrowed per-user by
    // App\Security\Voter\AudienceTargetableVoter one layer up.
    /** @return list<AgendaEvent> */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.endAt >= :now OR (e.endAt IS NULL AND e.startAt >= :now)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AgendaEvent> */
    public function findPast(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.endAt < :now OR (e.endAt IS NULL AND e.startAt < :now)')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('e.startAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
