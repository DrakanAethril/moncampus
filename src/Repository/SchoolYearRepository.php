<?php

namespace App\Repository;

use App\Entity\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolYear>
 */
class SchoolYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolYear::class);
    }

    public function countAll(?string $search = null): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applySearch($qb, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<SchoolYear> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('s.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('s.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('s.id', 'DESC')
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

        // No textual field to search on a school year - match against the start/end year instead.
        // DQL has no YEAR() function, but SUBSTRING() works since the date columns stringify as
        // 'YYYY-MM-DD'.
        $qb->andWhere("SUBSTRING(s.startDate, 1, 4) LIKE :search OR SUBSTRING(s.endDate, 1, 4) LIKE :search")
            ->setParameter('search', '%'.$search.'%');
    }
}
