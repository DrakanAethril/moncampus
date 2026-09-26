<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentCategory>
 */
class EquipmentCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentCategory::class);
    }

    /** @return list<EquipmentCategory> */
    public function findAllOrdered(): array
    {
        /** @var list<EquipmentCategory> $rows */
        $rows = $this->createQueryBuilder('e')->orderBy('e.name', 'ASC')->getQuery()->getResult();

        return $rows;
    }
}
