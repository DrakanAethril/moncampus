<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentStocktake;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentStocktake>
 */
class EquipmentStocktakeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentStocktake::class);
    }

    public function findOpen(): ?EquipmentStocktake
    {
        return $this->findOneBy(['closedAt' => null]);
    }

    /** @return list<EquipmentStocktake> */
    public function findClosed(int $limit = 20): array
    {
        /** @var list<EquipmentStocktake> $stocktakes */
        $stocktakes = $this->createQueryBuilder('s')
            ->where('s.closedAt IS NOT NULL')
            ->orderBy('s.closedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $stocktakes;
    }
}
