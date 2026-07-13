<?php

namespace App\Repository;

use App\Entity\PeriodType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodType>
 */
class PeriodTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodType::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<PeriodType> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('t.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('t.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('t.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('t.inactiveDate IS NULL');
        }
    }
}
