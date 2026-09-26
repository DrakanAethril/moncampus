<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentStocktake;
use App\Entity\EquipmentStocktakeLine;
use App\Entity\EquipmentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentStocktakeLine>
 */
class EquipmentStocktakeLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentStocktakeLine::class);
    }

    public function findForItem(EquipmentStocktake $stocktake, EquipmentItem $item): ?EquipmentStocktakeLine
    {
        return $this->findOneBy(['stocktake' => $stocktake, 'item' => $item]);
    }

    public function findCount(EquipmentStocktake $stocktake, EquipmentType $type): ?EquipmentStocktakeLine
    {
        return $this->findOneBy(['stocktake' => $stocktake, 'type' => $type, 'item' => null]);
    }

    /** @return array<int, true> item id => found, for the pieces ticked in this count */
    public function foundItemIds(EquipmentStocktake $stocktake): array
    {
        /** @var list<array{itemId: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.item) AS itemId')
            ->where('l.stocktake = :stocktake')
            ->andWhere('l.item IS NOT NULL')
            ->setParameter('stocktake', $stocktake)
            ->getQuery()
            ->getArrayResult();

        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['itemId']] = true;
        }

        return $found;
    }

    /** @return array<int, EquipmentStocktakeLine> type id => the numbers counted for it */
    public function countsByType(EquipmentStocktake $stocktake): array
    {
        /** @var list<EquipmentStocktakeLine> $lines */
        $lines = $this->createQueryBuilder('l')
            ->where('l.stocktake = :stocktake')
            ->andWhere('l.item IS NULL')
            ->setParameter('stocktake', $stocktake)
            ->getQuery()
            ->getResult();

        $byType = [];
        foreach ($lines as $line) {
            $byType[(int) $line->getType()->getId()] = $line;
        }

        return $byType;
    }
}
