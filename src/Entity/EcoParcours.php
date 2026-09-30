<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EcoParcoursStatus;
use App\Repository\EcoParcoursRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A teacher's reusable orienteering route - see design/design_campus_manager/README.md, "e-CO"
 * section, and reference/e-CO.dc.html screens 1d/1e. Owned by a teacher (ROLE_ECO), not a Program,
 * same reasoning as QuizTemplate. Always has exactly one Start and one Finish checkpoint
 * (App\Service\EcoParcoursFactory adds both at creation, alongside the requested number of
 * regular checkpoints) plus zero or more numbered ones in between - getStatus() below reflects
 * whether every checkpoint has been located yet from the mobile app.
 *
 * Shareable: $sharedWith lists colleagues (ROLE_TEACHER and ROLE_ECO both) who hold every right the
 * creator holds - configure, locate, run courses, delete, share further. $teacher stays the creator,
 * named on the list, but is not a privilege: isManagedBy() is the one rule, which the voter and both
 * repositories' "mine and shared with me" queries read.
 */
#[ORM\Entity(repositoryClass: EcoParcoursRepository::class)]
#[ORM\Table(name: 'eco_parcours')]
class EcoParcours
{
    use AuditableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', nullable: false)]
    private ?User $teacher = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'creation_date', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $creationDate;

    /** @var Collection<int, EcoCheckpoint> */
    #[ORM\OneToMany(mappedBy: 'parcours', targetEntity: EcoCheckpoint::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $checkpoints;

    /** @var Collection<int, EcoCourse> */
    #[ORM\OneToMany(mappedBy: 'parcours', targetEntity: EcoCourse::class)]
    private Collection $courses;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'eco_parcours_share')]
    #[ORM\JoinColumn(name: 'parcours_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['lastname' => 'ASC', 'firstname' => 'ASC'])]
    private Collection $sharedWith;

    /**
     * The IGN's reading of the ground the parcours covers - legs, rescue access, public forest -
     * as App\Service\Eco\EcoParcoursTerrainAnalyzer wrote it. A snapshot, not a relation: it is
     * only ever read whole, and it carries the fingerprint of the positions it was computed for,
     * so a flag moved since then shows it as out of date instead of silently wrong.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'terrain_analysis', type: Types::JSON, nullable: true)]
    private ?array $terrainAnalysis = null;

    #[ORM\Column(name: 'terrain_analyzed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $terrainAnalyzedAt = null;

    // Set when an analysis is wanted and not yet written - by the button, or by the last flag of
    // the parcours being located. The IGN's feature service answers in seconds per request, so the
    // analysis runs in app:eco:read-terrain, never in the request that asked for it.
    #[ORM\Column(name: 'terrain_requested_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $terrainRequestedAt = null;

    public function __construct(User $teacher)
    {
        $this->teacher = $teacher;
        $this->checkpoints = new ArrayCollection();
        $this->courses = new ArrayCollection();
        $this->sharedWith = new ArrayCollection();
        $this->creationDate = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    /** @return Collection<int, User> */
    public function getSharedWith(): Collection
    {
        return $this->sharedWith;
    }

    // The creator is never among the people it is shared with: they hold it already.
    public function shareWith(User $user): static
    {
        if ($user !== $this->teacher && !$this->sharedWith->contains($user)) {
            $this->sharedWith->add($user);
        }

        return $this;
    }

    public function unshareWith(User $user): static
    {
        $this->sharedWith->removeElement($user);

        return $this;
    }

    /** Whether this person holds the parcours - created it, or had it shared with them. */
    public function isManagedBy(User $user): bool
    {
        return $this->teacher === $user || $this->sharedWith->contains($user);
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

    public function getCreationDate(): \DateTimeImmutable
    {
        return $this->creationDate;
    }

    /** @return Collection<int, EcoCheckpoint> */
    public function getCheckpoints(): Collection
    {
        return $this->checkpoints;
    }

    public function addCheckpoint(EcoCheckpoint $checkpoint): static
    {
        if (!$this->checkpoints->contains($checkpoint)) {
            $this->checkpoints->add($checkpoint);
        }

        return $this;
    }

    public function removeCheckpoint(EcoCheckpoint $checkpoint): static
    {
        $this->checkpoints->removeElement($checkpoint);

        return $this;
    }

    // Regular (numbered) checkpoints only - excludes the auto-created Start/Finish, e.g. for the
    // "8 + D/A" count shown on 1d.
    /** @return list<EcoCheckpoint> */
    public function getRegularCheckpoints(): array
    {
        return array_values(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => \App\Enum\EcoCheckpointType::Checkpoint === $checkpoint->getType(),
        ));
    }

    /** @return Collection<int, EcoCourse> */
    public function getCourses(): Collection
    {
        return $this->courses;
    }

    // Draft (nothing located yet) / ToLocate (some but not all) / Ready (every checkpoint has
    // GPS coordinates) - see EcoParcoursStatus's own docblock for why this isn't a stored column.
    public function getStatus(): EcoParcoursStatus
    {
        $total = $this->checkpoints->count();
        if (0 === $total) {
            return EcoParcoursStatus::Draft;
        }

        $locatedCount = \count(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated(),
        ));

        if (0 === $locatedCount) {
            return EcoParcoursStatus::Draft;
        }

        return $locatedCount === $total ? EcoParcoursStatus::Ready : EcoParcoursStatus::ToLocate;
    }

    public function getLocatedCheckpointCount(): int
    {
        return \count(array_filter(
            $this->checkpoints->toArray(),
            static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated(),
        ));
    }

    // Only a Ready parcours can have courses created against it (screen 1d: "Courses" is greyed
    // out otherwise) - see App\Security\Voter\EcoParcoursVoter/App\Controller\EcoCourseController.
    public function isReady(): bool
    {
        return EcoParcoursStatus::Ready === $this->getStatus();
    }

    /** @return array<string, mixed>|null */
    public function getTerrainAnalysis(): ?array
    {
        return $this->terrainAnalysis;
    }

    public function getTerrainAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->terrainAnalyzedAt;
    }

    /** @param array<string, mixed> $analysis */
    public function recordTerrainAnalysis(array $analysis, \DateTimeImmutable $analyzedAt): static
    {
        $this->terrainAnalysis = $analysis;
        $this->terrainAnalyzedAt = $analyzedAt;
        $this->terrainRequestedAt = null;

        return $this;
    }

    /**
     * Adds shortest walks to an analysis already written - the pairs of flags runners ran in free
     * order, which the parcours' own order does not contain.
     *
     * @param array<string, float|null> $routes keyed "12-15", see EcoParcoursTerrainAnalyzer::pairKey()
     */
    public function addTerrainRoutes(array $routes): static
    {
        if (null === $this->terrainAnalysis || [] === $routes) {
            return $this;
        }

        $known = \is_array($this->terrainAnalysis['routes'] ?? null) ? $this->terrainAnalysis['routes'] : [];
        // A new array, not an in-place write: Doctrine compares the JSON column by value.
        $this->terrainAnalysis = ['routes' => $routes + $known] + $this->terrainAnalysis;

        return $this;
    }

    /** Whether an analysis exists and was computed for the flags where they stand now. */
    public function hasCurrentTerrainAnalysis(): bool
    {
        return null !== $this->terrainAnalysis
            && ($this->terrainAnalysis['fingerprint'] ?? null) === $this->locationFingerprint();
    }

    /**
     * The shortest walk between two flags, from the current analysis - null when there is none,
     * when that pair was never routed, or when the router found no way.
     */
    public function terrainRouteMeters(string $pairKey): ?float
    {
        if (!$this->hasCurrentTerrainAnalysis()) {
            return null;
        }

        $routes = $this->terrainAnalysis['routes'] ?? null;
        $meters = \is_array($routes) ? ($routes[$pairKey] ?? null) : null;

        return is_numeric($meters) ? (float) $meters : null;
    }

    /** Whether the current analysis has already been asked about this pair, route found or not. */
    public function hasTerrainRoute(string $pairKey): bool
    {
        $routes = $this->terrainAnalysis['routes'] ?? null;

        return $this->hasCurrentTerrainAnalysis() && \is_array($routes) && \array_key_exists($pairKey, $routes);
    }

    public function getTerrainRequestedAt(): ?\DateTimeImmutable
    {
        return $this->terrainRequestedAt;
    }

    // Asking twice keeps the first date: it is what the queue is ordered on.
    public function requestTerrainAnalysis(\DateTimeImmutable $requestedAt): static
    {
        $this->terrainRequestedAt ??= $requestedAt;

        return $this;
    }

    /**
     * Where every flag stands, as one string: what the terrain analysis is stamped with, and what
     * tells it apart from the parcours as it is now. Rounded to about a metre - a re-scan on the
     * same spot does not make the analysis stale.
     */
    public function locationFingerprint(): string
    {
        $parts = [];
        foreach ($this->checkpoints as $checkpoint) {
            $parts[] = \sprintf(
                '%d:%s',
                (int) $checkpoint->getId(),
                $checkpoint->isLocated()
                    ? \sprintf('%.5f,%.5f', (float) $checkpoint->getLatitude(), (float) $checkpoint->getLongitude())
                    : '-',
            );
        }
        sort($parts);

        return hash('xxh128', implode('|', $parts));
    }
}
