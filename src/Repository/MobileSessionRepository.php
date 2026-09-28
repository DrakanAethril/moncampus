<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MobileSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MobileSession>
 */
class MobileSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MobileSession::class);
    }

    /** The session a refresh token names, whether it is the generation to use now or the one before. */
    public function findOneBySelector(string $selector): ?MobileSession
    {
        return $this->createQueryBuilder('s')
            ->where('s.currentSelector = :selector OR s.previousSelector = :selector')
            ->setParameter('selector', $selector)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The phones « Mon profil » lists: neither revoked nor run out, most recently used first.
     *
     * @return list<MobileSession>
     */
    public function findLiveFor(User $user, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now)
            ->orderBy('s.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sessions dead since before $before - run out, or revoked. Kept that long after their end so a
     * « session expirée » can still be traced; nothing reads them once the app has stopped trying.
     */
    public function deleteDeadBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expiresAt < :before OR s.revokedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function countDeadBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.expiresAt < :before OR s.revokedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
