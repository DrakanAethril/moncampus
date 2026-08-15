<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Skill;
use App\Entity\SkillGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Skill>
 */
class SkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Skill::class);
    }

    /**
     * Every competency of the group, inactive ones included when asked, in referential order -
     * the counterpart of SkillGroupRepository::findAllOrderedForProgram(), and for the same
     * reason: the list reorders by drag-and-drop, so it cannot be paged.
     *
     * @return list<Skill>
     */
    public function findAllOrderedForSkillGroup(SkillGroup $skillGroup, bool $includeInactive = false): array
    {
        $builder = $this->createQueryBuilder('s')
            ->andWhere('s.skillGroup = :skillGroup')
            ->setParameter('skillGroup', $skillGroup)
            ->orderBy('s.order', 'ASC')
            ->addOrderBy('s.id', 'ASC');

        if (!$includeInactive) {
            $builder->andWhere('s.inactiveDate IS NULL');
        }

        /** @var list<Skill> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    public function countAllForSkillGroup(SkillGroup $skillGroup, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.skillGroup = :skillGroup')->setParameter('skillGroup', $skillGroup);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Skill> */
    public function findPageForSkillGroupOrderedByMostRecent(SkillGroup $skillGroup, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->leftJoin('s.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('s.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('s.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('s.skillGroup = :skillGroup')
            ->setParameter('skillGroup', $skillGroup)
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

        $qb->andWhere('s.label LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('s.inactiveDate IS NULL');
        }
    }
}
