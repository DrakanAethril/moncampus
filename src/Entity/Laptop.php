<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LaptopRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical laptop the formation center can lend to users. Whether it is currently on loan is
 * never stored here - it is derived from whether a LaptopLoan with no returnedAt exists for this
 * laptop, so there is nothing to keep in sync.
 */
#[ORM\Entity(repositoryClass: LaptopRepository::class)]
#[ORM\Table(name: 'laptop')]
#[ORM\UniqueConstraint(name: 'uniq_laptop_asset_tag', columns: ['asset_tag'])]
#[ORM\UniqueConstraint(name: 'uniq_laptop_serial_number', columns: ['serial_number'])]
// So that a number typed twice comes back as a field error on the inventory panel rather than as
// an integrity-constraint 500. Both columns have carried a unique index all along; what was
// missing on assetTag was the application-side check that turns it into something the operator can
// read without losing what they had just typed.
#[UniqueEntity(fields: ['assetTag'], message: 'laptopAssetTagAlreadyUsedMessage')]
#[UniqueEntity(fields: ['serialNumber'], message: 'laptopSerialNumberAlreadyUsedMessage')]
class Laptop
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The institution's own inventory number ("N° interne PC" on the CFC convention), not the
    // manufacturer's - that one is $serialNumber below.
    #[ORM\Column(name: 'asset_tag', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $assetTag;

    // Printed twice on the UFA convention (description block and article 2). Unique because two
    // laptops sharing a serial number always means a typing mistake, never a real fleet.
    #[ORM\Column(name: 'serial_number', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $serialNumber = '';

    // The sum article 2 of the convention holds the borrower liable for. DECIMAL, so Doctrine
    // hydrates it as a string and never as a float - money is never a floating-point value here,
    // same as ProgramLessonTypeCost::$cost. The usual 500 € is a form default, deliberately not a
    // column DEFAULT: that would only apply during the ALTER and drift from the mapping afterwards.
    #[ORM\Column(name: 'replacement_value', type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private ?string $replacementValue = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $brand = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $model = null;

    // Set once at creation (25b's "État initial") as a starting point before the laptop has ever
    // been lent - the inventory list's actual "current état" display still prefers the most
    // recent loan's return condition when one exists (LaptopLoanRepository::
    // findMostRecentReturnConditionsByLaptopIds()), falling back to this field only for a laptop
    // that has never been on loan yet.
    #[ORM\ManyToOne(targetEntity: LaptopConditionType::class)]
    #[ORM\JoinColumn(name: 'current_condition_type_id', nullable: true)]
    private ?LaptopConditionType $currentConditionType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $assetTag)
    {
        $this->assetTag = $assetTag;
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssetTag(): string
    {
        return $this->assetTag;
    }

    public function setAssetTag(string $assetTag): static
    {
        $this->assetTag = $assetTag;

        return $this;
    }

    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(string $serialNumber): static
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }

    /** Decimal string, e.g. "500.00" - never a float, see the property. */
    public function getReplacementValue(): ?string
    {
        return $this->replacementValue;
    }

    public function setReplacementValue(?string $replacementValue): static
    {
        $this->replacementValue = $replacementValue;

        return $this;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getCurrentConditionType(): ?LaptopConditionType
    {
        return $this->currentConditionType;
    }

    public function setCurrentConditionType(?LaptopConditionType $currentConditionType): static
    {
        $this->currentConditionType = $currentConditionType;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function getInactiveDate(): ?\DateTimeImmutable
    {
        return $this->inactiveDate;
    }

    public function setInactiveDate(?\DateTimeImmutable $inactiveDate): static
    {
        $this->inactiveDate = $inactiveDate;

        return $this;
    }
}
