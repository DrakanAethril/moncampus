<?php

namespace App\Repository;

use App\Entity\InternshipSkillGroup;
use App\Entity\Program;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipSkillGroup>
 */
class InternshipSkillGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipSkillGroup::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)')->where('g.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipSkillGroup> */
    public function findPageForProgramOrderedByMostRecent(Program $program, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('g.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('g.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('g.program = :program')
            ->setParameter('program', $program)
            ->orderBy('g.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Powers the booklet - only the program's active skill groups, with their active criteria
    // fetch-joined to avoid N+1 when rendering the competency grid.
    /** @return list<InternshipSkillGroup> */
    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('cr')
            ->leftJoin('g.criteria', 'cr', 'WITH', 'cr.inactiveDate IS NULL')
            ->where('g.program = :program')
            ->andWhere('g.inactiveDate IS NULL')
            ->setParameter('program', $program)
            ->orderBy('g.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('g.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('g.inactiveDate IS NULL');
        }
    }
}
