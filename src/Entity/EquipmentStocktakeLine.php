<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentStocktakeLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one count found for one type: a piece ticked as found (`$item` set), or the numbers counted
 * for a quantity type (`$item` null) - in the reserve, and in the rooms when somebody counted those
 * too. A quantity left blank was not counted and is not compared: an uncounted room is not a room
 * where everything went missing.
 */
#[ORM\Entity(repositoryClass: EquipmentStocktakeLineRepository::class)]
#[ORM\Table(name: 'equipment_stocktake_line')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_stocktake_item', columns: ['stocktake_id', 'item_id'])]
class EquipmentStocktakeLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EquipmentStocktake::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EquipmentStocktake $stocktake;

    #[ORM\ManyToOne(targetEntity: EquipmentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EquipmentType $type;

    #[ORM\ManyToOne(targetEntity: EquipmentItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?EquipmentItem $item;

    #[ORM\Column(name: 'counted_available', nullable: true)]
    private ?int $countedAvailable = null;

    #[ORM\Column(name: 'counted_in_use', nullable: true)]
    private ?int $countedInUse = null;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy;

    public function __construct(EquipmentStocktake $stocktake, EquipmentType $type, ?EquipmentItem $item, ?User $recordedBy)
    {
        $this->stocktake = $stocktake;
        $this->type = $type;
        $this->item = $item;
        $this->recordedBy = $recordedBy;
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStocktake(): EquipmentStocktake
    {
        return $this->stocktake;
    }

    public function getType(): EquipmentType
    {
        return $this->type;
    }

    public function getItem(): ?EquipmentItem
    {
        return $this->item;
    }

    public function getCountedAvailable(): ?int
    {
        return $this->countedAvailable;
    }

    public function getCountedInUse(): ?int
    {
        return $this->countedInUse;
    }

    public function count(?int $available, ?int $inUse, ?User $by): void
    {
        $this->countedAvailable = $available;
        $this->countedInUse = $inUse;
        $this->recordedBy = $by;
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }
}
