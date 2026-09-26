<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentMovement>
 */
class EquipmentMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentMovement::class);
    }

    /** @return list<EquipmentMovement> */
    public function findRecentForType(EquipmentType $type, int $limit = 50): array
    {
        /** @var list<EquipmentMovement> $movements */
        $movements = $this->createQueryBuilder('m')
            ->leftJoin('m.item', 'i')->addSelect('i')
            ->leftJoin('m.room', 'r')->addSelect('r')
            ->leftJoin('m.recordedBy', 'u')->addSelect('u')
            ->where('m.type = :type')
            ->setParameter('type', $type)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $movements;
    }

    /** @return list<EquipmentMovement> */
    public function findForItem(EquipmentItem $item): array
    {
        /** @var list<EquipmentMovement> $movements */
        $movements = $this->createQueryBuilder('m')
            ->leftJoin('m.room', 'r')->addSelect('r')
            ->leftJoin('m.recordedBy', 'u')->addSelect('u')
            ->where('m.item = :item')
            ->setParameter('item', $item)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $movements;
    }
}
