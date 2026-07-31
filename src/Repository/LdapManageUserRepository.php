<?php

namespace App\Repository;

use App\Entity\LdapManageUser;
use App\Entity\User;
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

    // leftJoin/addSelect on the linked User (single-valued, so no fetch-join-with-LIMIT pitfall
    // like a collection would have) - App\Controller\DirectoryUserController::data() reads that
    // User's id and manualGroups for every row on this page, and would otherwise fire an extra
    // query per row to lazy-load it.
    /** @return list<LdapManageUser> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.user', 'linkedUser')->addSelect('linkedUser')
            ->orderBy('u.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    // Backs the "Statut de l'ajout" line on the user edit screen (see
    // App\Controller\DirectoryUserController::edit()). Matches on the linked User first, falling
    // back to the login: rows predating LdapManageUser::$user were never linked (see that
    // property's docblock), and their login is the only thing tying them to a User row - same
    // two-pronged match as InternshipTutorLinkRepository::findActiveForTutorUser().
    //
    // Most recent wins: a login can be queued more than once (a first attempt that failed, then a
    // retry), and the line is meant to report where the account stands now, not how it started.
    public function findMostRecentForUser(User $user): ?LdapManageUser
    {
        return $this->createQueryBuilder('u')
            ->where('u.user = :user OR (u.user IS NULL AND u.login = :username)')
            ->setParameter('user', $user)
            ->setParameter('username', $user->getUsername())
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
