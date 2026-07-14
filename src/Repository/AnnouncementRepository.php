<?php

namespace App\Repository;

use App\Entity\Announcement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Announcement>
 */
class AnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }

    // Unfiltered by audience - callers (AnnouncementController, the dashboard widget) narrow this
    // down per-user via App\Security\Voter\AudienceTargetableVoter, same "fine at this scale"
    // convention as MessagingAccessChecker::searchCandidateRecipients() (a school's worth of
    // announcements, not millions of rows).
    /** @return list<Announcement> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.expiresAt IS NULL OR a.expiresAt > :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('a.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // Includes expired announcements - backs the staff-facing management list, unlike
    // findAllActive() above which is what regular users and the dashboard widget see.
    /** @return list<Announcement> */
    public function findAllOrderedByDate(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.creationDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
