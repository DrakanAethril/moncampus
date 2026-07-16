<?php

namespace App\Repository;

use App\Entity\InternshipEvaluationPeriod;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipEvaluationPeriod>
 */
class InternshipEvaluationPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipEvaluationPeriod::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('ep')->select('COUNT(ep.id)')->where('ep.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipEvaluationPeriod> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('ep')
            ->leftJoin('ep.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('ep.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('ep.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('ep.program = :program')
            ->setParameter('program', $program)
            ->orderBy('ep.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers every Livret Alternant evaluation screen (tutor/student/team, staff status screen,
    // booklet builder) - the candidate evaluation windows for one Program, oldest first.
    /** @return list<InternshipEvaluationPeriod> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('ep')
            ->where('ep.program = :program')
            ->andWhere('ep.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('ep.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('ep.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('ep.inactiveDate IS NULL');
        }
    }
}
