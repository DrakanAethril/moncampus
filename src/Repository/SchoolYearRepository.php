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

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<SchoolYear> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('s.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('s.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('s.id', 'DESC')
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

        // No textual field to search on a school year - match against the start/end year instead.
        // DQL has no YEAR() function, but SUBSTRING() works since the date columns stringify as
        // 'YYYY-MM-DD'.
        $qb->andWhere("SUBSTRING(s.startDate, 1, 4) LIKE :search OR SUBSTRING(s.endDate, 1, 4) LIKE :search")
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('s.inactiveDate IS NULL');
        }
    }

    /** @return list<SchoolYear> Every active SchoolYear, most recent first - backs the UFA liste's year selector. */
    public function findAllActiveOrderedByMostRecent(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.inactiveDate IS NULL')
            ->orderBy('s.startDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    // The SchoolYear whose date range contains today if one exists, otherwise the most recently
    // started active one (e.g. browsing over summer, between two school years) - used to
    // pre-select the UFA liste's year filter.
    public function findCurrentOrMostRecent(): ?SchoolYear
    {
        $today = new \DateTimeImmutable();

        $current = $this->createQueryBuilder('s')
            ->where('s.inactiveDate IS NULL')
            ->andWhere('s.startDate <= :today')
            ->andWhere('s.endDate >= :today')
            ->setParameter('today', $today)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null !== $current) {
            return $current;
        }

        return $this->createQueryBuilder('s')
            ->where('s.inactiveDate IS NULL')
            ->orderBy('s.startDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
