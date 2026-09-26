<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentCategory;
use App\Entity\EquipmentLocation;
use App\Entity\EquipmentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentType>
 */
class EquipmentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentType::class);
    }

    /**
     * The stock screen: every type, category first then name, narrowed by a category and a piece
     * of the name. The counters are columns, so this is the whole cost of the screen.
     *
     * @return list<EquipmentType>
     */
    public function findForStock(?EquipmentCategory $category = null, string $search = '', bool $alertsOnly = false): array
    {
        $qb = $this->createQueryBuilder('t')
            ->leftJoin('t.category', 'c')->addSelect('c')
            ->leftJoin('t.location', 'l')->addSelect('l')
            // Uncategorised types last, rather than first as a NULL would sort.
            ->addSelect('CASE WHEN c.id IS NULL THEN 1 ELSE 0 END AS HIDDEN uncategorised')
            ->orderBy('uncategorised', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->addOrderBy('t.name', 'ASC');

        if (null !== $category) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category);
        }

        if ('' !== $search) {
            $qb->andWhere('t.name LIKE :search OR t.brand LIKE :search OR t.model LIKE :search')
                ->setParameter('search', '%'.addcslashes($search, '%_\\').'%');
        }

        if ($alertsOnly) {
            $qb->andWhere('t.availableCount <= 0 OR (t.alertThreshold IS NOT NULL AND t.availableCount <= t.alertThreshold)');
        }

        /** @var list<EquipmentType> $types */
        $types = $qb->getQuery()->getResult();

        return $types;
    }

    /** @return array<int, int> category id => number of types filed under it */
    public function countByCategory(): array
    {
        /** @var list<array{categoryId: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.category) AS categoryId', 'COUNT(t.id) AS total')
            ->where('t.category IS NOT NULL')
            ->groupBy('categoryId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['categoryId']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countForLocation(EquipmentLocation $location): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.location = :location')
            ->setParameter('location', $location)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
