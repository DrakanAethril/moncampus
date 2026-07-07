<?php

namespace App\Repository;

use App\Entity\InternshipSkillCriterion;
use App\Entity\InternshipSkillGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InternshipSkillCriterion>
 */
class InternshipSkillCriterionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternshipSkillCriterion::class);
    }

    public function countAllForSkillGroup(InternshipSkillGroup $skillGroup, ?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('c')->select('COUNT(c.id)')->where('c.skillGroup = :skillGroup')->setParameter('skillGroup', $skillGroup);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<InternshipSkillCriterion> */
    public function findPageForSkillGroupOrderedByMostRecent(InternshipSkillGroup $skillGroup, int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('c.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('c.lastUpdatedBy', 'ub')->addSelect('ub')
            ->where('c.skillGroup = :skillGroup')
            ->setParameter('skillGroup', $skillGroup)
            ->orderBy('c.id', 'DESC')
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
