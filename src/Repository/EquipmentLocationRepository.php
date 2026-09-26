<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentLocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentLocation>
 */
class EquipmentLocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentLocation::class);
    }

    /** @return list<EquipmentLocation> */
    public function findAllOrdered(): array
    {
        /** @var list<EquipmentLocation> $rows */
        $rows = $this->createQueryBuilder('e')->orderBy('e.name', 'ASC')->getQuery()->getResult();

        return $rows;
    }
}
