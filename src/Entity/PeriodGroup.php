<?php

namespace App\Entity;

use App\Repository\PeriodGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A named set of Periods for one SchoolYear (e.g. "Calendrier SIO2 2026-2027") - a Program links
 * to a single PeriodGroup rather than to individual Period rows directly.
 */
#[ORM\Entity(repositoryClass: PeriodGroupRepository::class)]
#[ORM\Table(name: 'period_group')]
class PeriodGroup
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

    #[ORM\ManyToOne(targetEntity: SchoolYear::class, inversedBy: 'periodGroups')]
    #[ORM\JoinColumn(name: 'school_year_id', nullable: false)]
    #[Assert\NotNull]
    private ?SchoolYear $schoolYear = null;

    /** @var Collection<int, Period> */
    #[ORM\OneToMany(targetEntity: Period::class, mappedBy: 'periodGroup')]
    private Collection $periods;

    /** @var Collection<int, Program> */
    #[ORM\OneToMany(targetEntity: Program::class, mappedBy: 'periodGroup')]
    private Collection $programs;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    public function __construct(string $name, SchoolYear $schoolYear)
    {
        $this->name = $name;
        $this->creationDate = new \DateTimeImmutable();
        $this->periods = new ArrayCollection();
        $this->programs = new ArrayCollection();
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

    public function getSchoolYear(): ?SchoolYear
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(?SchoolYear $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        // Keep the inverse side in sync in memory - Doctrine only populates it from a fresh
        // query, not automatically from setting the owning side.
        if (null !== $schoolYear && !$schoolYear->getPeriodGroups()->contains($this)) {
            $schoolYear->getPeriodGroups()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Period> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    /** @return Collection<int, Program> */
    public function getPrograms(): Collection
    {
        return $this->programs;
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
