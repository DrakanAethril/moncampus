<?php

namespace App\Repository;

use App\Entity\InternshipBehaviorCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipBehaviorCriteria>
 */
class InternshipBehaviorCriteriaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipBehaviorCriteria::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipBehaviorCriteria> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('c.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('c.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('c.orderIndex', 'ASC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the booklet's behavior grid - only active criteria, in the establishment's chosen
    // display order, with their 5 levels fetch-joined to avoid N+1 when rendering.
    /** @return list<InternshipBehaviorCriteria> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('l')
            ->leftJoin('c.levels', 'l')
            ->where('c.inactiveDate IS NULL')
            ->orderBy('c.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('c.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('c.inactiveDate IS NULL');
        }
    }
}
