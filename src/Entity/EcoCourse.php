<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcoCheckpointType;
use App\Enum\EcoCourseMode;
use App\Enum\EcoCourseStatus;
use App\Enum\EcoMapVisibility;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One run of a Ready EcoParcours - see reference/e-CO.dc.html screen 1g. Manual 3-state cycle
 * (Prepared -> InProgress -> Closed, see EcoCourseStatus); runners join with $code + a pseudo,
 * no account (App\Entity\EcoRunner).
 *
 * « Balises spécifiques » (EcoCourseMode::SpecificCheckpoints) runs the course on a subset of the
 * parcours: Départ, Arrivée and the flags named in $specificCheckpoints, in order or not
 * ($specificOrdered). For that race the other flags do not exist - getRaceCheckpoints() is the one
 * list every reading of a course goes through (scan, ranking, live map, statistics, runner app),
 * never the parcours' own.
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

    // "Score = balises trouvées dans le temps imparti" (handoff, Ordre libre / Course au score
    // modes): the countdown the runner's app shows instead of a stopwatch. Null in Ordre imposé,
    // where the ranking is on time and there is nothing to run out of.
    #[ORM\Column(name: 'time_limit_minutes', nullable: true)]
    private ?int $timeLimitMinutes = null;

    /**
     * The regular flags a « Balises spécifiques » course is run on. Empty, and ignored, in every
     * other mode. Départ and Arrivée are never listed: every race has them.
     *
     * @var Collection<int, EcoCheckpoint>
     */
    #[ORM\ManyToMany(targetEntity: EcoCheckpoint::class)]
    #[ORM\JoinTable(name: 'eco_course_checkpoint')]
    #[ORM\JoinColumn(name: 'course_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'checkpoint_id', onDelete: 'CASCADE')]
    private Collection $specificCheckpoints;

    // « Dans l'ordre » (ranked on time, like Ordre imposé) or « Dans l'ordre de son choix » (a time
    // allowance, like Ordre libre). Only read in « Balises spécifiques ».
    #[ORM\Column(name: 'specific_ordered', options: ['default' => true])]
    private bool $specificOrdered = true;

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
        $this->specificCheckpoints = new ArrayCollection();
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

    /** @return Collection<int, EcoCheckpoint> */
    public function getSpecificCheckpoints(): Collection
    {
        return $this->specificCheckpoints;
    }

    // A flag of another parcours, Départ or Arrivée is never kept: none of them can be chosen.
    public function addSpecificCheckpoint(EcoCheckpoint $checkpoint): static
    {
        if ($checkpoint->getParcours() === $this->parcours
            && EcoCheckpointType::Checkpoint === $checkpoint->getType()
            && !$this->specificCheckpoints->contains($checkpoint)) {
            $this->specificCheckpoints->add($checkpoint);
        }

        return $this;
    }

    public function removeSpecificCheckpoint(EcoCheckpoint $checkpoint): static
    {
        $this->specificCheckpoints->removeElement($checkpoint);

        return $this;
    }

    public function isSpecificOrdered(): bool
    {
        return $this->specificOrdered;
    }

    public function setSpecificOrdered(bool $specificOrdered): static
    {
        $this->specificOrdered = $specificOrdered;

        return $this;
    }

    public function isSpecificCheckpoints(): bool
    {
        return EcoCourseMode::SpecificCheckpoints === $this->mode;
    }

    /** Whether a runner must take the flags in position order - what Ordre imposé means. */
    public function isOrdered(): bool
    {
        return EcoCourseMode::ImposedOrder === $this->mode
            || ($this->isSpecificCheckpoints() && $this->specificOrdered);
    }

    // A race run in order is ranked on time: there is no allowance to run out of.
    public function isTimeLimited(): bool
    {
        return !$this->isOrdered();
    }

    /**
     * The mode as the runner app knows it. The app predates « Balises spécifiques » and only tells
     * apart « in order » (imposed_order) from « as you like » (free_order, score): a subset course
     * is one of those two, run over fewer flags - which getRaceCheckpoints() already hands it.
     */
    public function runnerMode(): string
    {
        if (!$this->isSpecificCheckpoints()) {
            return $this->mode->value;
        }

        return $this->specificOrdered ? EcoCourseMode::ImposedOrder->value : EcoCourseMode::FreeOrder->value;
    }

    /**
     * The flags this race is run on, in position order: the whole parcours, or in « Balises
     * spécifiques » its Départ, its Arrivée and the flags chosen.
     *
     * @return list<EcoCheckpoint>
     */
    public function getRaceCheckpoints(): array
    {
        $checkpoints = array_values(array_filter(
            $this->parcours->getCheckpoints()->toArray(),
            fn (EcoCheckpoint $checkpoint): bool => !$this->isSpecificCheckpoints()
                || EcoCheckpointType::Checkpoint !== $checkpoint->getType()
                || $this->specificCheckpoints->contains($checkpoint),
        ));
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        return $checkpoints;
    }

    public function hasRaceCheckpoint(EcoCheckpoint $checkpoint): bool
    {
        return \in_array($checkpoint, $this->getRaceCheckpoints(), true);
    }

    #[Assert\Callback]
    public function validateSpecificCheckpoints(ExecutionContextInterface $context): void
    {
        if ($this->isSpecificCheckpoints() && $this->specificCheckpoints->isEmpty()) {
            $context->buildViolation('ecoCourseSpecificCheckpointsRequiredMessage')
                ->atPath('specificCheckpoints')
                ->addViolation();
        }
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

    public function getTimeLimitMinutes(): ?int
    {
        return $this->timeLimitMinutes;
    }

    public function setTimeLimitMinutes(?int $timeLimitMinutes): static
    {
        $this->timeLimitMinutes = $timeLimitMinutes;

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

    /**
     * The two steps of the manual cycle, shared by the web screen 1g and the teacher mobile app so
     * both move a course the same way. Each answers false, and changes nothing, when the course is
     * not in the state the step starts from: a second tap on « Démarrer » never restarts the clock.
     */
    public function start(\DateTimeImmutable $now): bool
    {
        if (EcoCourseStatus::Prepared !== $this->status) {
            return false;
        }

        $this->status = EcoCourseStatus::InProgress;
        $this->startedAt = $now;

        return true;
    }

    public function close(\DateTimeImmutable $now): bool
    {
        if (EcoCourseStatus::InProgress !== $this->status) {
            return false;
        }

        $this->status = EcoCourseStatus::Closed;
        $this->closedAt = $now;

        return true;
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
