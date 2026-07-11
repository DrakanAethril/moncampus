<?php

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
