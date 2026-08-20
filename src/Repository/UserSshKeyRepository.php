<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSshKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSshKey>
 */
class UserSshKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSshKey::class);
    }

    /** @return list<UserSshKey> */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.user = :user')
            ->setParameter('user', $user)
            ->orderBy('k.creationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every key on record, with its owner already loaded.
     *
     * Deliberately not filtered in SQL. Who counts as an administrator is answered by
     * User::getRoles(), which merges what LDAP grants with the groups an administrator set by hand -
     * a WHERE on the roles column would see only the first half and would quietly leave out anyone
     * made an administrator here rather than in the directory. The set is small by nature (a key per
     * machine, for the handful of people who have any), so the filtering happens in PHP against the
     * same authority every other screen uses. See App\Service\Guest\GuestAuthorizedKeys.
     *
     * @return list<UserSshKey>
     */
    public function findAllWithOwners(): array
    {
        return $this->createQueryBuilder('k')
            ->addSelect('u')
            ->join('k.user', 'u')
            ->orderBy('k.creationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
