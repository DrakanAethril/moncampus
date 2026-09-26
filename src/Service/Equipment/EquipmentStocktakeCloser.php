<?php

declare(strict_types=1);

namespace App\Service\Equipment;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentStocktake;
use App\Entity\EquipmentType;
use App\Entity\User;
use App\Enum\EquipmentItemStatus;
use App\Repository\EquipmentItemRepository;
use App\Repository\EquipmentStocktakeLineRepository;
use App\Repository\EquipmentTypeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a count found wrong, and closing it.
 *
 * - **A piece** the count expected and nobody ticked is short: it goes « Disparu » through an
 *   inventory-gap line. Expected means in scope, already there when the count started, and in the
 *   reserve - or in a room too, when the count went round the rooms.
 * - **A quantity** somebody counted is compared with the stored counter at the moment of closing;
 *   the count wins and the difference is written. A quantity left blank was not counted and is not
 *   compared.
 *
 * gaps() only reads, so the review screen shows exactly what close() will write.
 *
 * @phpstan-type QuantityGap array{type: EquipmentType, origin: EquipmentItemStatus, stored: int, counted: int, difference: int}
 * @phpstan-type Gaps array{missingItems: list<EquipmentItem>, quantities: list<QuantityGap>}
 */
final readonly class EquipmentStocktakeCloser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EquipmentItemRepository $items,
        private EquipmentTypeRepository $types,
        private EquipmentStocktakeLineRepository $lines,
        private EquipmentLedger $ledger,
    ) {
    }

    /**
     * @return list<EquipmentItemStatus> the statuses a piece must be in for this count to look for it
     */
    public static function countedStatuses(EquipmentStocktake $stocktake): array
    {
        return $stocktake->includesInUse()
            ? [EquipmentItemStatus::Available, EquipmentItemStatus::InUse]
            : [EquipmentItemStatus::Available];
    }

    /**
     * @return Gaps
     */
    public function gaps(EquipmentStocktake $stocktake): array
    {
        $found = $this->lines->foundItemIds($stocktake);
        $missing = array_values(array_filter(
            $this->items->findExpectedByCount($stocktake->getCategory(), self::countedStatuses($stocktake), $stocktake->getStartedAt()),
            static fn (EquipmentItem $item): bool => !isset($found[(int) $item->getId()]),
        ));

        $quantities = [];
        foreach ($this->lines->countsByType($stocktake) as $line) {
            $type = $line->getType();
            $this->entityManager->refresh($type);

            $pairs = [[EquipmentItemStatus::Available, $line->getCountedAvailable(), $type->getAvailableCount()]];
            if ($stocktake->includesInUse()) {
                $pairs[] = [EquipmentItemStatus::InUse, $line->getCountedInUse(), $type->getInUseCount()];
            }

            foreach ($pairs as [$origin, $counted, $stored]) {
                if (null !== $counted && $counted !== $stored) {
                    $quantities[] = ['type' => $type, 'origin' => $origin, 'stored' => $stored, 'counted' => $counted, 'difference' => $counted - $stored];
                }
            }
        }

        return ['missingItems' => $missing, 'quantities' => $quantities];
    }

    /**
     * Writes every gap and closes the count, in one transaction: a count half-applied would leave
     * the inventory neither as it was nor as it was found.
     */
    public function close(EquipmentStocktake $stocktake, User $by): void
    {
        if (!$stocktake->isOpen()) {
            throw new EquipmentStockException('equipmentStocktakeClosedMessage');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $gaps = $this->gaps($stocktake);
            $now = new \DateTimeImmutable();
            $shortages = 0;
            $surpluses = 0;

            foreach ($gaps['missingItems'] as $item) {
                $this->ledger->recordItemShortage($item, $now, null, $by);
                ++$shortages;
            }

            foreach ($gaps['quantities'] as $gap) {
                $this->ledger->recordQuantityGap($gap['type'], $gap['origin'], $gap['difference'], $now, null, $by);
                if ($gap['difference'] < 0) {
                    $shortages -= $gap['difference'];
                } else {
                    $surpluses += $gap['difference'];
                }
            }

            $stocktake->close($by, $shortages, $surpluses);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * The quantity types of the count's scope, for its screen.
     *
     * @return list<EquipmentType>
     */
    public function quantityTypes(EquipmentStocktake $stocktake): array
    {
        return array_values(array_filter(
            $this->types->findForStock($stocktake->getCategory()),
            static fn (EquipmentType $type): bool => !$type->isUnitTracked(),
        ));
    }
}
