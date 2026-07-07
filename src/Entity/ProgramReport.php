<?php

namespace App\Entity;

use App\Repository\ProgramReportRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A "Compte-Rendu" (meeting/session report) for a Program - ported from the reference app's
 * ephemeral, non-persisted export form into a real per-Program entity (soft-deactivated,
 * audited) so past reports stay listed and reprintable, mirroring the Topic/Skill pattern.
 */
#[ORM\Entity(repositoryClass: ProgramReportRepository::class)]
#[ORM\Table(name: 'program_report')]
class ProgramReport
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $day;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'referee_id', nullable: false)]
    #[Assert\NotNull]
    private ?User $referee = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Program::class, inversedBy: 'reports')]
    #[ORM\JoinColumn(name: 'program_id', nullable: false)]
    #[Assert\NotNull]
    private ?Program $program = null;

    /** @var Collection<int, Option> */
    #[ORM\ManyToMany(targetEntity: Option::class)]
    #[ORM\JoinTable(name: 'program_report_option')]
    private Collection $options;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    #[ORM\Column(name: 'inactive_date', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inactiveDate = null;

    // referee is nullable here despite being required in the DB/via Assert\NotNull, purely so
    // the form's empty_data can pass through a transiently-null value before the referee field
    // is actually submitted - mirrors Program::$cohort's own reasoning.
    public function __construct(string $title, \DateTimeImmutable $day, ?User $referee, Program $program)
    {
        $this->title = $title;
        $this->day = $day;
        $this->referee = $referee;
        $this->creationDate = new \DateTimeImmutable();
        $this->options = new ArrayCollection();
        $this->setProgram($program);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function setDay(\DateTimeImmutable $day): static
    {
        $this->day = $day;

        return $this;
    }

    public function getReferee(): ?User
    {
        return $this->referee;
    }

    public function setReferee(?User $referee): static
    {
        $this->referee = $referee;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getProgram(): ?Program
    {
        return $this->program;
    }

    public function setProgram(?Program $program): static
    {
        $this->program = $program;

        if (null !== $program && !$program->getReports()->contains($this)) {
            $program->getReports()->add($this);
        }

        return $this;
    }

    /** @return Collection<int, Option> */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(Option $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
        }

        return $this;
    }

    public function removeOption(Option $option): static
    {
        $this->options->removeElement($option);

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
