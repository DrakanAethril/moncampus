<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LdapManageAccount;
use App\Entity\User;
use App\Enum\LdapAccountAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapManageAccount>
 */
class LdapManageAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LdapManageAccount::class);
    }

    /**
     * The row that makes App\Service\LdapAccountRequestService refuse a second request: one gesture
     * at a time per account, or two scripts run on the same login in an order nobody chose.
     */
    public function findPendingForUser(User $user): ?LdapManageAccount
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.state IN (0, 1)')
            ->setParameter('user', $user)
            ->orderBy('a.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Backs the fiche's banner: where this account stands, whatever "there" is. */
    public function findMostRecentForUser(User $user): ?LdapManageAccount
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rows the application still owes something to: the script has finished (2 or 3) but nobody has
     * read the directory back, or the consequence of a confirmed rename has not been applied yet.
     *
     * Read by App\Command\ApplyLdapAccountRequestsCommand as well as by the fiche's polling - the
     * queue is what carries the work, so an administrator who closes their tab changes nothing.
     *
     * @return list<LdapManageAccount>
     */
    public function findAwaitingApplication(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.state = 2')
            ->andWhere('a.verificationDate IS NULL OR a.appliedAt IS NULL')
            ->orderBy('a.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAll(?string $search = null, ?LdapAccountAction $action = null, ?int $state = null): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applyFilters($qb, $search, $action, $state);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LdapManageAccount> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, ?LdapAccountAction $action = null, ?int $state = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applyFilters($qb, $search, $action, $state);

        return $qb->getQuery()->getResult();
    }

    private function applyFilters(QueryBuilder $qb, ?string $search, ?LdapAccountAction $action, ?int $state): void
    {
        if (null !== $search && '' !== $search) {
            $qb->join('a.user', 'u')
                ->andWhere("a.login LIKE :search OR a.newLogin LIKE :search OR CONCAT(u.firstname, ' ', u.lastname) LIKE :search")
                ->setParameter('search', '%'.$search.'%');
        }

        if (null !== $action) {
            $qb->andWhere('a.actionType = :action')->setParameter('action', $action);
        }

        if (null !== $state) {
            $qb->andWhere('a.state = :state')->setParameter('state', $state);
        }
    }
}
