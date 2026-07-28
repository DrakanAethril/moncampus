<?php

namespace App\Repository;

use App\Entity\LaptopConditionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LaptopConditionType>
 */
class LaptopConditionTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LaptopConditionType::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false): int
    {
        $qb = $this->createQueryBuilder('t')->select('COUNT(t.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<LaptopConditionType> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('t.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('t.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);

        return $qb->getQuery()->getResult();
    }

    // Backs the lend/return form pickers and the inventory filter dropdown - active types only,
    // ordered by orderIndex (screen 25c's drag-reorder), which is what drives the option order
    // seen in those forms.
    /** @return list<LaptopConditionType> */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.inactiveDate IS NULL')
            ->orderBy('t.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // Full list (active + inactive) in display order - backs screen 25c's own reorderable table
    // and the reorder endpoint's canonical re-fetch (see SettingsGroupsController::
    // reorderGroupTypes() for the same "don't trust the POSTed id list alone" reasoning).
    /** @return list<LaptopConditionType> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.orderIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextOrderIndex(): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.orderIndex)')
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $max ? ((int) $max + 1) : 0;
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('t.name LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('t.inactiveDate IS NULL');
        }
    }
}
