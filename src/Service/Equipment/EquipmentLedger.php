<?php

declare(strict_types=1);

namespace App\Service\Equipment;

use App\Entity\EquipmentItem;
use App\Entity\EquipmentMovement;
use App\Entity\EquipmentType;
use App\Entity\Room;
use App\Entity\User;
use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Repository\EquipmentMovementRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only way anything moves in Gestion > Matériel.
 *
 * Every gesture is one transaction that does three things together: write the journal line(s),
 * move the stored counters of the type, and - for a unit-tracked type - move the piece's status.
 *
 * The counters move through an atomic `UPDATE … SET x = x + :delta`, never through the entity: a
 * hydrate-add-flush would let two people taking the last cable at the same moment both succeed, and
 * one of the two exits would vanish from the count. The same statement refuses to go below zero, so
 * the stock cannot be overdrawn either. The counter's rule (what a line does) is
 * EquipmentMovementKind::delta(), the one App\Service\Equipment\EquipmentStockCounter sums to check.
 */
final readonly class EquipmentLedger
{
    /** A typo guard: 1 000 instead of 10 would otherwise create a thousand rows and codes. */
    public const int MAX_UNITS_PER_BATCH = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EquipmentCodeAllocator $codes,
        private EquipmentMovementRepository $movements,
    ) {
    }

    /**
     * Creates a type and its initial stock: `$quantity` pieces for a unit-tracked type (each with
     * its code, « Disponible », and its own intake line), or a quantity for the other kind.
     *
     * @return list<EquipmentItem> the pieces created, in code order - empty for a quantity type
     */
    public function createType(EquipmentType $type, int $quantity, User $by): array
    {
        $this->assertIntakeQuantity($type, $quantity, 0);

        return $this->transactional(function () use ($type, $quantity, $by): array {
            $type->setCreatedBy($by);
            $this->entityManager->persist($type);
            $this->entityManager->flush();

            return $this->intake($type, $quantity, $by, null);
        });
    }

    /**
     * A delivery of an existing type - « Ajouter des exemplaires ».
     *
     * @return list<EquipmentItem>
     */
    public function addStock(EquipmentType $type, int $quantity, User $by, ?string $note = null): array
    {
        $this->assertIntakeQuantity($type, $quantity, 1);

        return $this->transactional(fn (): array => $this->intake($type, $quantity, $by, $note));
    }

    /** « Utilisé » - the piece leaves the reserve, for a room when one is named. */
    public function deployItem(EquipmentItem $item, ?Room $room, User $by): void
    {
        $this->moveItem($item, EquipmentItemStatus::Available, EquipmentItemStatus::InUse, EquipmentMovementKind::Deploy, $room, $by);
    }

    /** « Disponible » - back into the reserve. */
    public function returnItem(EquipmentItem $item, User $by): void
    {
        $this->moveItem($item, EquipmentItemStatus::InUse, EquipmentItemStatus::Available, EquipmentMovementKind::Return, null, $by);
    }

    public function deployQuantity(EquipmentType $type, int $quantity, ?Room $room, User $by): void
    {
        $this->moveQuantity($type, EquipmentMovementKind::Deploy, $quantity, $room, $by);
    }

    public function returnQuantity(EquipmentType $type, int $quantity, User $by): void
    {
        $this->moveQuantity($type, EquipmentMovementKind::Return, $quantity, null, $by);
    }

    /**
     * « Disparu » or « Hors d'usage » for one piece. It leaves the count it was in - the reserve or
     * the rooms - and stays out until it is found, repaired or disposed of.
     */
    public function declareItemIncident(
        EquipmentItem $item,
        EquipmentMovementKind $kind,
        EquipmentIncidentCause $cause,
        ?Room $room,
        \DateTimeImmutable $occurredAt,
        ?string $note,
        User $by,
    ): void {
        $this->assertIncident($kind);

        $this->transactional(function () use ($item, $kind, $cause, $room, $occurredAt, $note, $by): void {
            $this->entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);
            $origin = $item->getStatus();

            if (!$origin->isInService()) {
                throw new EquipmentStockException('equipmentItemStatusChangedMessage');
            }

            $type = $item->getType();
            $this->applyDelta($type, $kind->delta(1, $origin));
            $item->moveTo($kind->statusAfter() ?? $origin, null);
            $this->entityManager->persist(new EquipmentMovement($type, $item, $kind, 1, $by, $room, $note, $occurredAt, $origin, $cause));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /**
     * The same for a number of pieces of a quantity type, taken from the count they were in.
     */
    public function declareQuantityIncident(
        EquipmentType $type,
        EquipmentMovementKind $kind,
        int $quantity,
        EquipmentItemStatus $origin,
        EquipmentIncidentCause $cause,
        ?Room $room,
        \DateTimeImmutable $occurredAt,
        ?string $note,
        User $by,
    ): void {
        $this->assertIncident($kind);
        $this->assertQuantityType($type, $quantity);

        if (!$origin->isInService()) {
            throw new \LogicException('An incident takes its pieces from the reserve or from the rooms.');
        }

        $this->transactional(function () use ($type, $kind, $quantity, $origin, $cause, $room, $occurredAt, $note, $by): void {
            $this->applyDelta($type, $kind->delta($quantity, $origin));
            $this->entityManager->persist(new EquipmentMovement($type, null, $kind, $quantity, $by, $room, $note, $occurredAt, $origin, $cause));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /**
     * « Retrouvé » (a Missing piece) or « Réparé » (an OutOfOrder one): back into the reserve, and
     * the line points at the incident it answers, so the report takes that loss back out of the
     * year it was declared in.
     */
    public function resolveItem(EquipmentItem $item, EquipmentMovementKind $kind, ?string $note, User $by): void
    {
        $answered = $kind->resolves() ?? throw new \LogicException('Only Found and Repaired answer an incident.');

        $this->transactional(function () use ($item, $kind, $answered, $note, $by): void {
            $this->entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);

            if ($item->getStatus() !== $answered->statusAfter()) {
                throw new EquipmentStockException('equipmentItemStatusChangedMessage');
            }

            $type = $item->getType();
            $incident = $this->movements->findOpenIncidents($type, $answered, $item)[0]['incident'] ?? null;

            $this->applyDelta($type, $kind->delta(1));
            $item->moveTo(EquipmentItemStatus::Available, null);
            $this->entityManager->persist(new EquipmentMovement($type, $item, $kind, 1, $by, null, $note, null, null, null, $incident));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /**
     * The same for a number of pieces of a quantity type. The quantity is spread over the open
     * incidents of that kind, newest first - one line per incident answered - and refused beyond
     * what those incidents still hold: nobody finds more cables than were lost.
     */
    public function resolveQuantity(EquipmentType $type, EquipmentMovementKind $kind, int $quantity, ?string $note, User $by): void
    {
        $answered = $kind->resolves() ?? throw new \LogicException('Only Found and Repaired answer an incident.');
        $this->assertQuantityType($type, $quantity);

        $this->transactional(function () use ($type, $kind, $answered, $quantity, $note, $by): void {
            // Two people answering the same incidents at once must not both succeed.
            $this->entityManager->refresh($type, LockMode::PESSIMISTIC_WRITE);

            $open = $this->movements->findOpenIncidents($type, $answered);
            if (array_sum(array_column($open, 'remaining')) < $quantity) {
                throw new EquipmentStockException('equipmentNothingToResolveMessage');
            }

            $this->applyDelta($type, $kind->delta($quantity));

            $left = $quantity;
            foreach ($open as ['incident' => $incident, 'remaining' => $remaining]) {
                $share = min($left, $remaining);
                $this->entityManager->persist(new EquipmentMovement($type, null, $kind, $share, $by, null, $note, null, null, null, $incident));
                $left -= $share;

                if (0 === $left) {
                    break;
                }
            }

            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /** « Mettre au rebut » - the end of life of a piece out of order. Its code is never reused. */
    public function disposeItem(EquipmentItem $item, ?string $note, User $by): void
    {
        $this->transactional(function () use ($item, $note, $by): void {
            $this->entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);

            if (EquipmentItemStatus::OutOfOrder !== $item->getStatus()) {
                throw new EquipmentStockException('equipmentItemStatusChangedMessage');
            }

            $item->moveTo(EquipmentItemStatus::Disposed, null);
            $this->entityManager->persist(new EquipmentMovement($item->getType(), $item, EquipmentMovementKind::Disposed, 1, $by, null, $note));
            $this->entityManager->flush();
        });
    }

    /** « Commander » - the pieces count as coming until they are received or the order cancelled. */
    public function order(EquipmentType $type, int $quantity, ?string $note, User $by): void
    {
        if ($quantity < 1) {
            throw new EquipmentStockException('equipmentQuantityTooSmallMessage');
        }

        $this->transactional(function () use ($type, $quantity, $note, $by): void {
            $this->applyDelta($type, EquipmentMovementKind::Ordered->delta($quantity));
            $this->entityManager->persist(new EquipmentMovement($type, null, EquipmentMovementKind::Ordered, $quantity, $by, null, $note));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /**
     * « Réceptionner » - part or all of what is on order arrives: the order count goes down, and
     * the pieces come in exactly as a delivery does (codes and all, for a unit-tracked type).
     *
     * @return list<EquipmentItem> the pieces created, for the labels screen
     */
    public function receiveOrder(EquipmentType $type, int $quantity, ?string $note, User $by): array
    {
        $this->assertIntakeQuantity($type, $quantity, 1);

        return $this->transactional(function () use ($type, $quantity, $note, $by): array {
            $this->assertOnOrder($type, $quantity);
            $this->applyDelta($type, EquipmentMovementKind::Received->delta($quantity));
            $this->entityManager->persist(new EquipmentMovement($type, null, EquipmentMovementKind::Received, $quantity, $by, null, $note));

            return $this->intake($type, $quantity, $by, $note);
        });
    }

    /** « Annuler » part or all of what is on order. */
    public function cancelOrder(EquipmentType $type, int $quantity, ?string $note, User $by): void
    {
        if ($quantity < 1) {
            throw new EquipmentStockException('equipmentQuantityTooSmallMessage');
        }

        $this->transactional(function () use ($type, $quantity, $note, $by): void {
            $this->assertOnOrder($type, $quantity);
            $this->applyDelta($type, EquipmentMovementKind::OrderCancelled->delta($quantity));
            $this->entityManager->persist(new EquipmentMovement($type, null, EquipmentMovementKind::OrderCancelled, $quantity, $by, null, $note));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    /** Nothing is received or cancelled beyond what is on order - read under the type's row lock. */
    private function assertOnOrder(EquipmentType $type, int $quantity): void
    {
        $this->entityManager->refresh($type, LockMode::PESSIMISTIC_WRITE);

        if ($type->getOnOrderCount() < $quantity) {
            throw new EquipmentStockException('equipmentMoreThanOrderedMessage');
        }
    }

    /** @return list<EquipmentItem> */
    private function intake(EquipmentType $type, int $quantity, User $by, ?string $note): array
    {
        if (0 === $quantity) {
            return [];
        }

        if (!$type->isUnitTracked()) {
            $this->applyDelta($type, EquipmentMovementKind::Intake->delta($quantity));
            $this->entityManager->persist(new EquipmentMovement($type, null, EquipmentMovementKind::Intake, $quantity, $by, null, $note));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);

            return [];
        }

        $this->applyDelta($type, EquipmentMovementKind::Intake->delta($quantity));
        [$first] = $this->codes->reserve($quantity);

        $items = [];
        for ($number = $first; $number < $first + $quantity; ++$number) {
            $item = new EquipmentItem($type, $number);
            $this->entityManager->persist($item);
            $this->entityManager->persist(new EquipmentMovement($type, $item, EquipmentMovementKind::Intake, 1, $by, null, $note));
            $items[] = $item;
        }

        $this->entityManager->flush();
        $this->entityManager->refresh($type);

        return $items;
    }

    private function moveItem(
        EquipmentItem $item,
        EquipmentItemStatus $from,
        EquipmentItemStatus $to,
        EquipmentMovementKind $kind,
        ?Room $room,
        User $by,
    ): void {
        $this->transactional(function () use ($item, $from, $to, $kind, $room, $by): void {
            // Re-read under lock: the status on screen may be a minute old, and two clicks on
            // « Utilisé » must not deploy the same mouse twice.
            $this->entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);

            if ($item->getStatus() !== $from) {
                throw new EquipmentStockException('equipmentItemStatusChangedMessage');
            }

            $type = $item->getType();
            $this->applyDelta($type, $kind->delta(1));
            $item->moveTo($to, $room);
            $this->entityManager->persist(new EquipmentMovement($type, $item, $kind, 1, $by, $room));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    private function moveQuantity(EquipmentType $type, EquipmentMovementKind $kind, int $quantity, ?Room $room, User $by): void
    {
        if ($type->isUnitTracked()) {
            throw new \LogicException('A unit-tracked type moves piece by piece.');
        }

        if ($quantity < 1) {
            throw new EquipmentStockException('equipmentQuantityTooSmallMessage');
        }

        $this->transactional(function () use ($type, $kind, $quantity, $room, $by): void {
            $this->applyDelta($type, $kind->delta($quantity));
            $this->entityManager->persist(new EquipmentMovement($type, null, $kind, $quantity, $by, $room));
            $this->entityManager->flush();
            $this->entityManager->refresh($type);
        });
    }

    private function assertIncident(EquipmentMovementKind $kind): void
    {
        if (!$kind->isIncident()) {
            throw new \LogicException('Only Missing and OutOfOrder are incidents.');
        }
    }

    private function assertQuantityType(EquipmentType $type, int $quantity): void
    {
        if ($type->isUnitTracked()) {
            throw new \LogicException('A unit-tracked type moves piece by piece.');
        }

        if ($quantity < 1) {
            throw new EquipmentStockException('equipmentQuantityTooSmallMessage');
        }
    }

    private function assertIntakeQuantity(EquipmentType $type, int $quantity, int $minimum): void
    {
        if ($quantity < $minimum) {
            throw new EquipmentStockException('equipmentQuantityTooSmallMessage');
        }

        if ($type->isUnitTracked() && $quantity > self::MAX_UNITS_PER_BATCH) {
            throw new EquipmentStockException('equipmentTooManyUnitsMessage');
        }
    }

    /**
     * A transaction on the connection rather than EntityManager::wrapInTransaction(), which closes
     * the manager on *any* exception - including the refusals this class throws on purpose, after
     * which the request could no longer so much as read a flash message's user. Every refusal is
     * raised before anything is persisted, so rolling the connection back leaves nothing behind.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Moves the three counters in one statement, which also refuses to take any of them below zero -
     * the stock screen must never promise a spare that is not there.
     */
    private function applyDelta(EquipmentType $type, EquipmentCounterDelta $delta): void
    {
        if ($delta->isZero()) {
            return;
        }

        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE equipment_type
                SET available_count = available_count + :available,
                    in_use_count = in_use_count + :inUse,
                    on_order_count = on_order_count + :onOrder
              WHERE id = :id
                AND available_count + :available >= 0
                AND in_use_count + :inUse >= 0
                AND on_order_count + :onOrder >= 0',
            [
                'available' => $delta->available,
                'inUse' => $delta->inUse,
                'onOrder' => $delta->onOrder,
                'id' => $type->getId(),
            ],
        );

        if (1 !== $affected) {
            throw new EquipmentStockException('equipmentNotEnoughStockMessage');
        }
    }
}
