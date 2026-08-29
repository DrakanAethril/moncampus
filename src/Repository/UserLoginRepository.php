<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserLogin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLogin>
 */
class UserLoginRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLogin::class);
    }

    /**
     * The row for one login, whoever holds it and whether or not they still answer to it.
     *
     * One row per login by construction (the UNIQUE index), which is what lets the reservation rule
     * be a single lookup rather than a scan.
     */
    public function findOneByLogin(string $login): ?UserLogin
    {
        return $this->findOneBy(['login' => $login]);
    }

    /**
     * Every login this account has ever answered to, oldest first, the current one last.
     *
     * Ordered on `releasedAt` rather than on `assignedAt`: the backfill could not date the logins
     * it reconstructed, so `assignedAt` is null on a good many rows, while a released login always
     * knows when it stopped being the answer. The current row sorts last because its `releasedAt`
     * is null - which is why the ordering is written out rather than left to MySQL's NULL placement.
     *
     * @return list<UserLogin>
     */
    public function findHistoryFor(User $user): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->setParameter('user', $user)
            ->addSelect('CASE WHEN l.releasedAt IS NULL THEN 1 ELSE 0 END AS HIDDEN isCurrent')
            ->orderBy('isCurrent', 'ASC')
            ->addOrderBy('l.releasedAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The row this account answers to today, if the history knows about it. */
    public function findCurrentFor(User $user): ?UserLogin
    {
        return $this->findOneBy(['user' => $user, 'releasedAt' => null]);
    }
}
