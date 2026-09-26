<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentType;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentMovementKind;
use App\Service\Equipment\EquipmentLossReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EquipmentMovement>
 *
 * @phpstan-import-type IncidentRow from EquipmentLossReport
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

    /**
     * The incidents of this kind not yet fully answered by a Found or Repaired line, newest first,
     * each with what is left to answer. For a piece, narrowed to that piece.
     *
     * @return list<array{incident: EquipmentMovement, remaining: int}>
     */
    public function findOpenIncidents(EquipmentType $type, EquipmentMovementKind $kind, ?EquipmentItem $item = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('(SELECT COALESCE(SUM(r.quantity), 0) FROM App\Entity\EquipmentMovement r WHERE r.resolves = m) AS resolved')
            ->where('m.type = :type')
            ->andWhere('m.kind = :kind')
            ->setParameter('type', $type)
            ->setParameter('kind', $kind)
            ->orderBy('m.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if (null !== $item) {
            $qb->andWhere('m.item = :item')->setParameter('item', $item);
        }

        /** @var list<array{0: EquipmentMovement, resolved: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $open = [];
        foreach ($rows as $row) {
            $remaining = $row[0]->getQuantity() - (int) $row['resolved'];
            if ($remaining > 0) {
                $open[] = ['incident' => $row[0], 'remaining' => $remaining];
            }
        }

        return $open;
    }

    /**
     * The incidents that happened between two dates, each with how much of it has been answered
     * since (whenever that was) - the input of App\Service\Equipment\EquipmentLossReport.
     *
     * @return list<IncidentRow>
     */
    public function findIncidentRows(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{kind: EquipmentMovementKind|string, quantity: int|string, cause: EquipmentIncidentCause|string|null, occurredAt: \DateTimeImmutable, typeId: int|string, typeName: string, unitPrice: string|null, categoryName: string|null, roomName: string|null, resolved: int|string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.kind AS kind', 'm.quantity AS quantity', 'm.cause AS cause', 'm.occurredAt AS occurredAt')
            ->addSelect('t.id AS typeId', 't.name AS typeName', 't.unitPrice AS unitPrice', 'c.name AS categoryName', 'r.name AS roomName')
            ->addSelect('(SELECT COALESCE(SUM(a.quantity), 0) FROM App\Entity\EquipmentMovement a WHERE a.resolves = m) AS resolved')
            ->join('m.type', 't')
            ->leftJoin('t.category', 'c')
            ->leftJoin('m.room', 'r')
            ->where('m.kind IN (:kinds)')
            ->andWhere('m.occurredAt BETWEEN :from AND :to')
            ->setParameter('kinds', [EquipmentMovementKind::Missing, EquipmentMovementKind::OutOfOrder])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            // Depending on the hydration path an enum column arrives as the case or as its value.
            'kind' => $row['kind'] instanceof EquipmentMovementKind ? $row['kind']->value : $row['kind'],
            'quantity' => (int) $row['quantity'],
            'resolved' => (int) $row['resolved'],
            'cause' => $row['cause'] instanceof EquipmentIncidentCause ? $row['cause']->value : $row['cause'],
            'occurredAt' => $row['occurredAt'],
            'typeId' => (int) $row['typeId'],
            'typeName' => $row['typeName'],
            'unitPrice' => $row['unitPrice'],
            'categoryName' => $row['categoryName'],
            'roomName' => $row['roomName'],
        ], $rows);
    }
}
