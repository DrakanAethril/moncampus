<?php

namespace App\Repository;

use App\Entity\LdapManageUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapManageUser>
 */
class LdapManageUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LdapManageUser::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LdapManageUser> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    // Checked by App\Service\LoginGenerator alongside UserRepository - a login can be reserved
    // here (set at insert time, see LdapManageUser::$user's docblock) before the corresponding
    // User row's own username is even flushed in the same request, and old rows from before that
    // change may hold a login no User row was ever created for, so neither table alone is a
    // complete picture of "is this login taken".
    public function loginExists(string $login): bool
    {
        return null !== $this->findOneBy(['login' => $login]);
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere(
            'u.firstname LIKE :search OR u.lastname LIKE :search OR u.userType LIKE :search '.
            'OR u.userGroups LIKE :search OR u.login LIKE :search',
        )->setParameter('search', '%'.$search.'%');
    }
}
