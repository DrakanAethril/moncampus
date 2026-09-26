<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EquipmentItemStatus;
use App\Repository\EquipmentItemRepository;
use App\Service\Equipment\EquipmentCode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One piece of a unit-tracked App\Entity\EquipmentType - this mouse, labelled `CA-0142-0`.
 *
 * Only the number is stored; the printed code is derived (App\Service\Equipment\EquipmentCode), so
 * the check digit can never disagree with it. The number is drawn from one sequence shared by every
 * type and is never handed out twice, even once the piece is gone.
 *
 * `$status` and `$room` are the consequence of the last journal line - they only move through
 * App\Service\Equipment\EquipmentLedger, which writes that line at the same time.
 */
#[ORM\Entity(repositoryClass: EquipmentItemRepository::class)]
#[ORM\Table(name: 'equipment_item')]
#[ORM\UniqueConstraint(name: 'uniq_equipment_item_code_number', columns: ['code_number'])]
#[ORM\Index(name: 'idx_equipment_item_labeled', columns: ['labeled_at'])]
class EquipmentItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EquipmentType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EquipmentType $type;

    #[ORM\Column(name: 'code_number')]
    private int $codeNumber;

    #[ORM\Column(length: 20, enumType: EquipmentItemStatus::class)]
    private EquipmentItemStatus $status = EquipmentItemStatus::Available;

    /** Where it is used, when it is used and somebody said where. */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: EquipmentLocation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EquipmentLocation $location = null;

    #[ORM\Column(name: 'serial_number', length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $serialNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** When somebody ticked « étiquette posée ». Null is what the « À étiqueter » list reads. */
    #[ORM\Column(name: 'labeled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $labeledAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    public function __construct(EquipmentType $type, int $codeNumber)
    {
        $this->type = $type;
        $this->codeNumber = $codeNumber;
        $this->location = $type->getLocation();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): EquipmentType
    {
        return $this->type;
    }

    public function getCodeNumber(): int
    {
        return $this->codeNumber;
    }

    public function getCode(): string
    {
        return EquipmentCode::format($this->codeNumber);
    }

    public function getStatus(): EquipmentItemStatus
    {
        return $this->status;
    }

    /** Reserved to App\Service\Equipment\EquipmentLedger, which writes the journal line with it. */
    public function moveTo(EquipmentItemStatus $status, ?Room $room): void
    {
        $this->status = $status;
        $this->room = $room;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
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

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): static
    {
        $serialNumber = null !== $serialNumber ? trim($serialNumber) : null;
        $this->serialNumber = '' === $serialNumber ? null : $serialNumber;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $notes = null !== $notes ? trim($notes) : null;
        $this->notes = '' === $notes ? null : $notes;

        return $this;
    }

    public function getLabeledAt(): ?\DateTimeImmutable
    {
        return $this->labeledAt;
    }

    public function isLabeled(): bool
    {
        return null !== $this->labeledAt;
    }

    public function setLabeledAt(?\DateTimeImmutable $labeledAt): static
    {
        $this->labeledAt = $labeledAt;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }
}
