<?php

namespace App\Repository;

use App\Entity\EvaluationPeriodGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EvaluationPeriodGroup>
 */
class EvaluationPeriodGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvaluationPeriodGroup::class);
    }

    // The whole list is rendered in one page load (no server-side DataTables paging) - a school's
    // number of evaluation-period groups is always small (a handful of terms/semesters setups),
    // and the design's expandable parent-group/child-period rows (12a) don't fit DataTables' own
    // per-row pagination/search model without a lot of extra machinery this doesn't need yet.
    /** @return list<EvaluationPeriodGroup> */
    public function findAllOrderedByName(bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('g')
            ->leftJoin('g.periods', 'p')->addSelect('p')
            ->orderBy('g.name', 'ASC');
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    /** @return list<EvaluationPeriodGroup> */
    public function findAllActiveOrderedByName(): array
    {
        return $this->findAllOrderedByName(false);
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('g.inactiveDate IS NULL');
        }
    }
}
