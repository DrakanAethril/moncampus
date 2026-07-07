<?php

namespace App\Repository;

use App\Entity\Program;
use App\Entity\ProgramReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProgramReport>
 */
class ProgramReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProgramReport::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<ProgramReport> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.referee', 're')->addSelect('re')
            ->leftJoin('r.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('r.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('r.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('r.program = :program')
            ->setParameter('program', $program)
            ->orderBy('r.id', 'DESC')
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

        $qb->andWhere('r.title LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('r.inactiveDate IS NULL');
        }
    }
}
