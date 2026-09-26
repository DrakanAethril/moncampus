<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentLocation;
use App\Entity\EquipmentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentItem>
 */
class EquipmentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EquipmentItem::class);
    }

    /** @return list<EquipmentItem> */
    public function findForType(EquipmentType $type): array
    {
        /** @var list<EquipmentItem> $items */
        $items = $this->createQueryBuilder('i')
            ->leftJoin('i.room', 'r')->addSelect('r')
            ->leftJoin('i.location', 'l')->addSelect('l')
            ->where('i.type = :type')
            ->setParameter('type', $type)
            ->orderBy('i.codeNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * The pieces whose label nobody has ticked as stuck on yet, in code order - the order they are
     * typed on the Dymo.
     *
     * @return list<EquipmentItem>
     */
    public function findUnlabeled(): array
    {
        /** @var list<EquipmentItem> $items */
        $items = $this->createQueryBuilder('i')
            ->join('i.type', 't')->addSelect('t')
            ->where('i.labeledAt IS NULL')
            ->orderBy('i.codeNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countUnlabeled(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.labeledAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $numbers
     *
     * @return array<int, EquipmentItem> code number => piece
     */
    public function findByCodeNumbers(array $numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        /** @var list<EquipmentItem> $items */
        $items = $this->createQueryBuilder('i')
            ->join('i.type', 't')->addSelect('t')
            ->where('i.codeNumber IN (:numbers)')
            ->setParameter('numbers', $numbers)
            ->getQuery()
            ->getResult();

        $byNumber = [];
        foreach ($items as $item) {
            $byNumber[$item->getCodeNumber()] = $item;
        }

        return $byNumber;
    }

    /**
     * A batch just created, read back by its code range - what the « codes à étiqueter » screen
     * shows after a creation.
     *
     * @return list<EquipmentItem>
     */
    public function findByCodeRange(int $from, int $to): array
    {
        /** @var list<EquipmentItem> $items */
        $items = $this->createQueryBuilder('i')
            ->join('i.type', 't')->addSelect('t')
            ->where('i.codeNumber BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.codeNumber', 'ASC')
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countForLocation(EquipmentLocation $location): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.location = :location')
            ->setParameter('location', $location)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
