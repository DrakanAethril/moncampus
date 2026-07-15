<?php

namespace App\Repository;

use App\Entity\Laptop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Laptop>
 */
class LaptopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Laptop::class);
    }

    public function countAll(?string $search = null, bool $includeInactive = false, ?int $conditionTypeId = null): int
    {
        $qb = $this->createQueryBuilder('l')->select('COUNT(l.id)');
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);
        $this->applyConditionFilter($qb, $conditionTypeId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<Laptop> */
    public function findPageOrderedByMostRecent(int $offset, int $limit, ?string $search = null, bool $includeInactive = false, ?int $conditionTypeId = null): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('l.inactivatedBy', 'ib')->addSelect('ib')
            ->leftJoin('l.lastUpdatedBy', 'ub')->addSelect('ub')
            ->orderBy('l.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $this->applySearch($qb, $search);
        $this->applyActiveFilter($qb, $includeInactive);
        $this->applyConditionFilter($qb, $conditionTypeId);

        return $qb->getQuery()->getResult();
    }

    private function applySearch(QueryBuilder $qb, ?string $search): void
    {
        if (null === $search || '' === $search) {
            return;
        }

        $qb->andWhere('l.assetTag LIKE :search OR l.brand LIKE :search OR l.model LIKE :search')
            ->setParameter('search', '%'.$search.'%');
    }

    // By default, only active rows (inactiveDate IS NULL) are listed - the inventory tab passes
    // includeInactive=true to also mix retired laptops into the same list instead of hiding them
    // entirely, same convention as RoomRepository.
    private function applyActiveFilter(QueryBuilder $qb, bool $includeInactive): void
    {
        if (!$includeInactive) {
            $qb->andWhere('l.inactiveDate IS NULL');
        }
    }

    // "État" filter dropdown on the inventory list - a Laptop carries no état of its own (see its
    // class docblock), so this matches the same "most recent returned loan" definition as
    // LaptopLoanRepository::findMostRecentReturnConditionsByLaptopIds() uses to display it.
    private function applyConditionFilter(QueryBuilder $qb, ?int $conditionTypeId): void
    {
        if (null === $conditionTypeId) {
            return;
        }

        $qb->andWhere('EXISTS (
                SELECT 1 FROM App\Entity\LaptopLoan loan
                WHERE loan.laptop = l
                AND loan.returnConditionType = :conditionTypeId
                AND loan.returnedAt = (
                    SELECT MAX(loan2.returnedAt) FROM App\Entity\LaptopLoan loan2
                    WHERE loan2.laptop = l AND loan2.returnedAt IS NOT NULL
                )
            )')
            ->setParameter('conditionTypeId', $conditionTypeId);
    }
}
