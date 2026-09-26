<?php

declare(strict_types=1);

namespace App\Service\Equipment;

use App\Counter\CounterDrift;
use App\Counter\CounterRun;
use App\Counter\RecomputableCounter;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use Doctrine\DBAL\Connection;

/**
 * The three stored counters of App\Entity\EquipmentType, checked against the journal.
 *
 * The journal is summed per (type, kind) in SQL and folded here with EquipmentMovementKind::delta(),
 * the rule the live update applies - never a second copy of it in a CASE expression.
 *
 * The rows are locked (`FOR UPDATE`) before the journal is read: a movement being recorded right
 * now either committed before the lock was granted, and its line is read, or waits for the lock and
 * adds its delta on top of the corrected value. Either way nothing is counted twice or lost.
 */
final readonly class EquipmentStockCounter implements RecomputableCounter
{
    public const string NAME = 'equipment_stock';

    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Matériel : disponibles, utilisés et en commande de chaque type';
    }

    public function recompute(?int $id = null, bool $dryRun = false): CounterRun
    {
        return $this->connection->transactional(function (Connection $connection) use ($id, $dryRun): CounterRun {
            $filter = null !== $id ? ' WHERE id = :id' : '';
            $parameters = null !== $id ? ['id' => $id] : [];

            /** @var list<array{id: int|string, name: string, available_count: int|string, in_use_count: int|string, on_order_count: int|string}> $types */
            $types = $connection->fetchAllAssociative(
                'SELECT id, name, available_count, in_use_count, on_order_count FROM equipment_type'.$filter.' ORDER BY id FOR UPDATE',
                $parameters,
            );

            /** @var list<array{type_id: int|string, kind: string, origin: string|null, total: int|string}> $sums */
            $sums = $connection->fetchAllAssociative(
                'SELECT type_id, kind, origin, SUM(quantity) AS total FROM equipment_movement'
                .(null !== $id ? ' WHERE type_id = :id' : '').' GROUP BY type_id, kind, origin',
                $parameters,
            );

            $expected = [];
            foreach ($sums as $sum) {
                $typeId = (int) $sum['type_id'];
                $origin = null !== $sum['origin'] ? EquipmentItemStatus::from($sum['origin']) : null;
                $delta = EquipmentMovementKind::from($sum['kind'])->delta((int) $sum['total'], $origin);
                $expected[$typeId] = ($expected[$typeId] ?? EquipmentCounterDelta::zero())->plus($delta);
            }

            $drifts = [];
            foreach ($types as $type) {
                $typeId = (int) $type['id'];
                $should = $expected[$typeId] ?? EquipmentCounterDelta::zero();
                $stored = [
                    'available_count' => (int) $type['available_count'],
                    'in_use_count' => (int) $type['in_use_count'],
                    'on_order_count' => (int) $type['on_order_count'],
                ];
                $computed = [
                    'available_count' => $should->available,
                    'in_use_count' => $should->inUse,
                    'on_order_count' => $should->onOrder,
                ];

                if ($stored === $computed) {
                    continue;
                }

                $drifts[] = new CounterDrift($typeId, $type['name'], $stored, $computed);

                if (!$dryRun) {
                    $connection->executeStatement(
                        'UPDATE equipment_type SET available_count = :available_count, in_use_count = :in_use_count, on_order_count = :on_order_count WHERE id = :id',
                        $computed + ['id' => $typeId],
                    );
                }
            }

            return new CounterRun(self::NAME, \count($types), $drifts, $dryRun);
        });
    }
}
