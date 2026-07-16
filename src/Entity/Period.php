<?php

namespace App\Entity;

use App\Repository\PeriodRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named date range the school can schedule against (e.g. a term or a half-day slot).
 */
#[ORM\Entity(repositoryClass: PeriodRepository::class)]
#[ORM\Table(name: 'period')]
class Period
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    // Nullable in PHP (unlike the DB column) purely so the form data mapper can pass through a
    // transiently-null value when a date field is submitted blank, without a TypeError -
    // #[Assert\NotNull] still rejects it before persist().
    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'startDate')]
    private ?\DateTimeImmutable $endDate;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    #[ORM\ManyToOne(targetEntity: PeriodType::class)]
    #[ORM\JoinColumn(name: 'period_type_id', nullable: false)]
    #[Assert\NotNull]
    private ?PeriodType $type = null;

    #[ORM\ManyToOne(targetEntity: PeriodGroup::class, inversedBy: 'periods')]
    #[ORM\JoinColumn(name: 'period_group_id', nullable: false)]
    #[Assert\NotNull]
    private ?PeriodGroup $periodGroup = null;

    // Inverse side - Modality owns this relation (see Modality::$periods), same reasoning as
    // Program::$modalities. Empty means the period applies to every modality, not to none.
    /** @var Collection<int, Modality> */
    #[ORM\ManyToMany(targetEntity: Modality::class, mappedBy: 'periods')]
    private Collection $modalities;

    // type is nullable here despite being required in the DB/via Assert\NotNull, purely so the
    // form's empty_data can pass through a transiently-null value before the type field is
    // actually submitted - mirrors ProgramReport::$referee's own reasoning.
    public function __construct(string $name, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, ?PeriodType $type, PeriodGroup $periodGroup)
    {
        $this->name = $name;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->creationDate = new \DateTimeImmutable();
        $this->type = $type;
        $this->setPeriodGroup($periodGroup);
        $this->modalities = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

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

    public function getType(): ?PeriodType
    {
        return $this->type;
    }

    public function setType(?PeriodType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPeriodGroup(): ?PeriodGroup
    {
        return $this->periodGroup;
    }

    public function setPeriodGroup(?PeriodGroup $periodGroup): static
    {
        $this->periodGroup = $periodGroup;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $periodGroup && !$periodGroup->getPeriods()->contains($this)) {
            $periodGroup->getPeriods()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Modality> */
    public function getModalities(): Collection
    {
        return $this->modalities;
    }

    public function addModality(Modality $modality): static
    {
        $modality->addPeriod($this);

        return $this;
    }

    public function removeModality(Modality $modality): static
    {
        $modality->removePeriod($this);

        return $this;
    }
}
