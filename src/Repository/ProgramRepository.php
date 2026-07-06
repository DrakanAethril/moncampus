<?php

namespace App\Repository;

use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Program>
 */
class ProgramRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Program::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Program> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.cohort', 'c')->addSelect('c')
            ->leftJoin('p.schoolYear', 'y')->addSelect('y')
            ->leftJoin('p.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('p.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('p.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('p.id', 'DESC')
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

        $qb->andWhere('p.name LIKE :search OR p.shortName LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the settings/structure
    // tabs pass includeInactive=true to also mix deactivated rows into the same list instead
    // of hiding them entirely.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('p.inactiveDate IS NULL');
        }
    }

    // Populates the "options" and "modalities" collections on an already-fetched page of
    // Programs in two extra queries, instead of one lazy-load query per row per collection -
    // the LEFT JOINs (rather than inner joins) are required so Doctrine also marks each
    // collection as initialized (empty) for Programs with no linked option/modality at all.
    // Two separate queries avoid the row-duplication a single query joining both collections
    // at once would produce.
    /** @param list<Program> $programs */
    public function hydrateOptionsAndModalities(array $programs): void
    {
        if ([] === $programs) {
            return;
        }

        $ids = array_map(static fn (Program $program): ?int => $program->getId(), $programs);

        $this->createQueryBuilder('p')
            ->select('p', 'o')
            ->leftJoin('p.options', 'o')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('p')
            ->select('p', 'm')
            ->leftJoin('p.modalities', 'm')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }
}
