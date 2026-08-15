<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Program;
use App\Entity\SkillGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SkillGroup>
 */
class SkillGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkillGroup::class);
    }

    public function countAllForProgram(Program $program, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('g')->select('COUNT(g.id)')->where('g.program = :program')->setParameter('program', $program);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<SkillGroup> */
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

    // Powers the booklet and the tutor evaluation form. Active skills and gating Options (see
    // SkillGroup::$options) are fetch-joined to avoid N+1 when the caller filters by the
    // student's own Options afterward.
    /** @return list<SkillGroup> */
    /**
     * Every group of the program, inactive ones included when asked, in referential order.
     *
     * Backs the settings list, which renders server-side and reorders by drag-and-drop: every row
     * has to be in the DOM at once, so there is no paging here - a program holds a handful of
     * blocks. Ordering falls back to the id so rows created before SkillGroup::$order existed
     * (all sharing 0) keep a stable, reproducible sequence.
     *
     * @return list<SkillGroup>
     */
    public function findAllOrderedForProgram(Program $program, bool $includeInactive = false): array
    {
        $builder = $this->createQueryBuilder('g')
            ->andWhere('g.program = :program')
            ->setParameter('program', $program)
            ->orderBy('g.order', 'ASC')
            ->addOrderBy('g.id', 'ASC');

        if (!$includeInactive) {
            $builder->andWhere('g.inactiveDate IS NULL');
        }

        /** @var list<SkillGroup> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    public function findAllActiveForProgram(Program $program): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('sk', 'o')
            ->leftJoin('g.skills', 'sk', 'WITH', 'sk.inactiveDate IS NULL')
            ->leftJoin('g.options', 'o')
            ->andWhere('g.inactiveDate IS NULL')
            ->andWhere('g.program = :program')
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
