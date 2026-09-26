<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EquipmentTypeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One kind of small equipment of Gestion > Matériel - « Souris USB », « Câble HDMI 2 m ».
 *
 * Two ways of following it, chosen at creation and frozen afterwards:
 *
 * - **à l'unité** (`$unitTracked`): every piece is an App\Entity\EquipmentItem with its own label
 *   code, status and history;
 * - **en quantité**: only numbers - how many are available, how many are in use.
 *
 * The three counters are **stored** rather than summed at display, by decision: the stock screen
 * must not read the whole journal to draw a badge. They are never written through this entity -
 * App\Service\Equipment\EquipmentLedger moves them with an atomic `UPDATE … + delta` in the same
 * transaction as the journal line, so two people taking the last cable at the same moment cannot
 * lose a movement to each other, and App\Service\Equipment\EquipmentStockCounter sums the journal
 * again (button, or `app:counters:recompute` at night) to catch whatever drifted. Hence no setters.
 */
#[ORM\Entity(repositoryClass: EquipmentTypeRepository::class)]
#[ORM\Table(name: 'equipment_type')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_type_name', columns: ['name'])]
#[UniqueEntity(fields: ['name'], message: 'equipmentNameAlreadyUsedMessage')]
class EquipmentType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: EquipmentCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EquipmentCategory $category = null;

    #[ORM\Column(name: 'unit_tracked')]
    private bool $unitTracked = false;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $brand = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $model = null;

    #[ORM\Column(name: 'unit_price', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $unitPrice = null;

    /** At or under this many available, the type turns orange on the stock screen. Null: no alert. */
    #[ORM\Column(name: 'alert_threshold', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $alertThreshold = null;

    /** What a re-order should bring the available stock back up to. */
    #[ORM\Column(name: 'target_stock', nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $targetStock = null;

    /** A supplier reference, or the link to the product page - free text, it is only ever read. */
    #[ORM\Column(name: 'supplier_reference', length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $supplierReference = null;

    /** Where the spares of this type are kept, and where new pieces land. */
    #[ORM\ManyToOne(targetEntity: EquipmentLocation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EquipmentLocation $location = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'available_count', options: ['default' => 0])]
    private int $availableCount = 0;

    #[ORM\Column(name: 'in_use_count', options: ['default' => 0])]
    private int $inUseCount = 0;

    #[ORM\Column(name: 'on_order_count', options: ['default' => 0])]
    private int $onOrderCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getCategory(): ?EquipmentCategory
    {
        return $this->category;
    }

    public function setCategory(?EquipmentCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isUnitTracked(): bool
    {
        return $this->unitTracked;
    }

    /** Only meaningful before the first save: the form of an existing type does not offer it. */
    public function setUnitTracked(bool $unitTracked): static
    {
        $this->unitTracked = $unitTracked;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = self::blankToNull($brand);

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = self::blankToNull($model);

        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(?string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getAlertThreshold(): ?int
    {
        return $this->alertThreshold;
    }

    public function setAlertThreshold(?int $alertThreshold): static
    {
        $this->alertThreshold = $alertThreshold;

        return $this;
    }

    public function getTargetStock(): ?int
    {
        return $this->targetStock;
    }

    public function setTargetStock(?int $targetStock): static
    {
        $this->targetStock = $targetStock;

        return $this;
    }

    public function getSupplierReference(): ?string
    {
        return $this->supplierReference;
    }

    public function setSupplierReference(?string $supplierReference): static
    {
        $this->supplierReference = self::blankToNull($supplierReference);

        return $this;
    }

    public function getLocation(): ?EquipmentLocation
    {
        return $this->location;
    }

    public function setLocation(?EquipmentLocation $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = self::blankToNull($notes);

        return $this;
    }

    public function getAvailableCount(): int
    {
        return $this->availableCount;
    }

    public function getInUseCount(): int
    {
        return $this->inUseCount;
    }

    public function getOnOrderCount(): int
    {
        return $this->onOrderCount;
    }

    /**
     * The colour of the stock badge: red when nothing is left to replace anything with, orange at
     * or under the alert threshold, green otherwise.
     */
    public function stockLevel(): string
    {
        if ($this->availableCount <= 0) {
            return 'red';
        }

        if (null !== $this->alertThreshold && $this->availableCount <= $this->alertThreshold) {
            return 'gold';
        }

        return 'green';
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }
}
