<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EquipmentIncidentCause;
use App\Enum\EquipmentItemStatus;
use App\Enum\EquipmentMovementKind;
use App\Repository\EquipmentMovementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the equipment journal - the source of truth of Gestion > Matériel.
 *
 * **Append-only**, like App\Entity\GameEntry: nothing here is ever edited or deleted, a mistake is
 * answered by another line. The counters of App\Entity\EquipmentType are a stored reading of this
 * table, and the school year a line counts towards is a reading of `$occurredAt`, never a column.
 *
 * `$item` is set for a unit-tracked type (and `$quantity` is then 1); a quantity-tracked type
 * moves in numbers and names no piece.
 *
 * An incident also carries `$origin` - the count its pieces left, Disponible or Utilisé - and a
 * `$cause`. A « Retrouvé » or « Réparé » line points at the incident it answers (`$resolves`), which
 * is what lets the annual report take a loss back out of the year it was declared in, whenever the
 * piece turns up.
 */
#[ORM\Entity(repositoryClass: EquipmentMovementRepository::class)]
#[ORM\Table(name: 'equipment_movement')]
#[ORM\Index(name: 'idx_equipment_movement_type_kind', columns: ['type_id', 'kind'])]
#[ORM\Index(name: 'idx_equipment_movement_occurred', columns: ['occurred_at'])]
class EquipmentMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EquipmentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EquipmentType $type;

    #[ORM\ManyToOne(targetEntity: EquipmentItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?EquipmentItem $item;

    #[ORM\Column(length: 20, enumType: EquipmentMovementKind::class)]
    private EquipmentMovementKind $kind;

    #[ORM\Column]
    private int $quantity;

    /** The day it happened - what the school year is read on. */
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy;

    /** Where it happened. Optional, always: nothing on this screen is imputed to a class. */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Room $room;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    /** The count an incident's pieces came from - Available or InUse. */
    #[ORM\Column(length: 20, nullable: true, enumType: EquipmentItemStatus::class)]
    private ?EquipmentItemStatus $origin;

    #[ORM\Column(length: 20, nullable: true, enumType: EquipmentIncidentCause::class)]
    private ?EquipmentIncidentCause $cause;

    /** The incident a Found or Repaired line answers. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'resolves_id', nullable: true, onDelete: 'CASCADE')]
    private ?EquipmentMovement $resolves;

    public function __construct(
        EquipmentType $type,
        ?EquipmentItem $item,
        EquipmentMovementKind $kind,
        int $quantity,
        ?User $recordedBy,
        ?Room $room = null,
        ?string $note = null,
        ?\DateTimeImmutable $occurredAt = null,
        ?EquipmentItemStatus $origin = null,
        ?EquipmentIncidentCause $cause = null,
        ?EquipmentMovement $resolves = null,
    ) {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('A journal line moves at least one piece.');
        }

        $this->type = $type;
        $this->item = $item;
        $this->kind = $kind;
        $this->quantity = $quantity;
        $this->recordedBy = $recordedBy;
        $this->room = $room;
        $note = null !== $note ? trim($note) : null;
        $this->note = '' === $note ? null : $note;
        $this->recordedAt = new \DateTimeImmutable();
        $this->occurredAt = $occurredAt ?? $this->recordedAt;
        $this->origin = $origin;
        $this->cause = $cause;
        $this->resolves = $resolves;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): EquipmentType
    {
        return $this->type;
    }

    public function getItem(): ?EquipmentItem
    {
        return $this->item;
    }

    public function getKind(): EquipmentMovementKind
    {
        return $this->kind;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getOrigin(): ?EquipmentItemStatus
    {
        return $this->origin;
    }

    public function getCause(): ?EquipmentIncidentCause
    {
        return $this->cause;
    }

    public function getResolves(): ?self
    {
        return $this->resolves;
    }
}
