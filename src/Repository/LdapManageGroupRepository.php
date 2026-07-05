<?php

namespace App\Repository;

use App\Entity\LdapManageGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LdapManageGroup>
 */
class LdapManageGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LdapManageGroup::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LdapManageGroup> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('g')
            ->orderBy('g.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('g.name LIKE :search OR g.description LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    /** @return list<string> */
    public function findAllNames(): array
    {
        return array_column(
            $this->createQueryBuilder('g')
                ->select('g.name')
                ->orderBy('g.name', 'ASC')
                ->getQuery()
                ->getScalarResult(),
            'name',
        );
    }
}
