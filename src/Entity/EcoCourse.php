<?php

namespace App\Entity;

use App\Enum\EcoCourseMode;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoMapVisibility;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One run of a Ready EcoParcours - see reference/e-CO.dc.html screen 1g. Manual 3-state cycle
 * (Prepared -> InProgress -> Closed, see EcoCourseStatus); runners join with $code + a pseudo,
 * no account (App\Entity\EcoRunner).
 */
#[ORM\Entity(repositoryClass: \App\Repository\EcoCourseRepository::class)]
#[ORM\Table(name: 'eco_course')]
#[ORM\UniqueConstraint(name: 'eco_course_code_unique', columns: ['code'])]
class EcoCourse
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoParcours::class, inversedBy: 'courses')]
    #[ORM\JoinColumn(name: 'parcours_id', nullable: false)]
    private ?EcoParcours $parcours = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    // 6-char alphanumeric, uppercase, easy to read aloud/type on a phone (e.g. "7GX4K2") - see
    // App\Service\EcoCourseCodeGenerator.
    #[ORM\Column(length: 6)]
    private ?string $code = null;

    #[ORM\Column(length: 20, enumType: EcoCourseMode::class)]
    private EcoCourseMode $mode = EcoCourseMode::ImposedOrder;

    #[ORM\Column(name: 'teams_enabled')]
    private bool $teamsEnabled = false;

    #[ORM\Column(name: 'map_visibility', length: 30, enumType: EcoMapVisibility::class)]
    private EcoMapVisibility $mapVisibility = EcoMapVisibility::AllCheckpoints;

    #[ORM\Column(name: 'safety_alerts_enabled')]
    private bool $safetyAlertsEnabled = true;

    #[ORM\Column(length: 20, enumType: EcoCourseStatus::class)]
    private EcoCourseStatus $status = EcoCourseStatus::Prepared;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, EcoRunner> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: EcoRunner::class)]
    private Collection $runners;

    /** @var Collection<int, EcoTeam> */
    #[ORM\OneToMany(mappedBy: 'course', targetEntity: EcoTeam::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $teams;

    public function __construct(EcoParcours $parcours, User $teacher)
    {
        $this->parcours = $parcours;
        $this->teacher = $teacher;
        $this->runners = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParcours(): ?EcoParcours
    {
        return $this->parcours;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getMode(): EcoCourseMode
    {
        return $this->mode;
    }

    public function setMode(EcoCourseMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function isTeamsEnabled(): bool
    {
        return $this->teamsEnabled;
    }

    public function setTeamsEnabled(bool $teamsEnabled): static
    {
        $this->teamsEnabled = $teamsEnabled;

        return $this;
    }

    public function getMapVisibility(): EcoMapVisibility
    {
        return $this->mapVisibility;
    }

    public function setMapVisibility(EcoMapVisibility $mapVisibility): static
    {
        $this->mapVisibility = $mapVisibility;

        return $this;
    }

    public function isSafetyAlertsEnabled(): bool
    {
        return $this->safetyAlertsEnabled;
    }

    public function setSafetyAlertsEnabled(bool $safetyAlertsEnabled): static
    {
        $this->safetyAlertsEnabled = $safetyAlertsEnabled;

        return $this;
    }

    public function getStatus(): EcoCourseStatus
    {
        return $this->status;
    }

    public function setStatus(EcoCourseStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, EcoRunner> */
    public function getRunners(): Collection
    {
        return $this->runners;
    }

    /** @return Collection<int, EcoTeam> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(EcoTeam $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }

        return $this;
    }
}
