<?php

namespace App\Entity;

use App\Repository\ProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Cohort's offering for a given SchoolYear (e.g. SIO1 for 2025-2026), the entity Options
 * and Modalities are actually attached to.
 */
#[ORM\Entity(repositoryClass: ProgramRepository::class)]
#[ORM\Table(name: 'program')]
class Program
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

    #[ORM\Column(name: 'short_name', length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $shortName;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    // Nullable in PHP (unlike the DB column) purely so the form data mapper can pass through a
    // transiently-null value while re-applying the "cohort"/"schoolYear" fields' submitted data
    // after empty_data runs, without a TypeError - #[Assert\NotNull] still rejects it before
    // persist().
    #[ORM\ManyToOne(targetEntity: Cohort::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'cohort_id', nullable: false)]
    #[Assert\NotNull]
    private ?Cohort $cohort = null;

    #[ORM\ManyToOne(targetEntity: SchoolYear::class, inversedBy: 'programs')]
    #[ORM\JoinColumn(name: 'school_year_id', nullable: false)]
    #[Assert\NotNull]
    private ?SchoolYear $schoolYear = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class, mappedBy: 'programs')]
    private Collection $options;

    /** @var Collection<int, Modality> */
    #[ORM\ManyToMany(targetEntity: Modality::class, mappedBy: 'programs')]
    private Collection $modalities;

    public function __construct(string $name, string $shortName, Cohort $cohort, SchoolYear $schoolYear)
    {
        $this->name = $name;
        $this->shortName = $shortName;
        $this->creationDate = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
        $this->modalities = new ArrayCollection();
        $this->setCohort($cohort);
        $this->setSchoolYear($schoolYear);
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

    public function getShortName(): string
    {
        return $this->shortName;
    }

    public function setShortName(string $shortName): static
    {
        $this->shortName = $shortName;

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

    public function getCohort(): ?Cohort
    {
        return $this->cohort;
    }

    public function setCohort(?Cohort $cohort): static
    {
        $this->cohort = $cohort;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a
        // fresh query, not automatically from setting the owning side.
        if (null !== $cohort && !$cohort->getPrograms()->contains($this)) {
            $cohort->getPrograms()->add($this);
        }

        return $this;
    }

    public function getSchoolYear(): ?SchoolYear
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(?SchoolYear $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        if (null !== $schoolYear && !$schoolYear->getPrograms()->contains($this)) {
            $schoolYear->getPrograms()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    /** @return Collection<int, Modality> */
    public function getModalities(): Collection
    {
        return $this->modalities;
    }
}
