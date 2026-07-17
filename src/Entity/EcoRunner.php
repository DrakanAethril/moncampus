<?php

namespace App\Entity;

use App\Enum\EcoRunnerStatus;
use App\Repository\EcoRunnerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A course participant - no account, just a pseudo entered on join (screen 3d). $joinToken is the
 * bearer identity the mobile app stores locally and sends with every scan/telemetry/SOS call
 * (App\Controller\Api\Eco*): it is also what "reprise après crash" resumes from - the app
 * persists it locally, so relaunching after a crash mid-race re-authenticates the same runner
 * instead of re-joining, without ever needing to re-scan the Start checkpoint. Safety-monitoring
 * fields ($lastLatitude/$lastLongitude/$lastPositionAt/$sosActive/$appLeftAt) are the live state
 * screens 1h/4d read every ~10s; the full history behind them lives in EcoPositionPing/
 * EcoAppEvent.
 */
#[ORM\Entity(repositoryClass: EcoRunnerRepository::class)]
#[ORM\Table(name: 'eco_runner')]
#[ORM\UniqueConstraint(name: 'eco_runner_join_token_unique', columns: ['join_token'])]
class EcoRunner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: EcoCourse::class, inversedBy: 'runners')]
    #[ORM\JoinColumn(name: 'course_id', nullable: false)]
    private ?EcoCourse $course = null;

    #[ORM\ManyToOne(targetEntity: EcoTeam::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: true)]
    private ?EcoTeam $team = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $pseudo = null;

    #[ORM\Column(name: 'join_token', length: 64)]
    private ?string $joinToken = null;

    #[ORM\Column(length: 20, enumType: EcoRunnerStatus::class)]
    private EcoRunnerStatus $status = EcoRunnerStatus::NotStarted;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    // Score-mode points, or the running "balises trouvées" count for FreeOrder - unused
    // (null) in ImposedOrder, where ranking is purely by elapsed time between $startedAt/$finishedAt.
    #[ORM\Column(name: 'score_value', nullable: true)]
    private ?int $scoreValue = null;

    #[ORM\Column(name: 'sos_active')]
    private bool $sosActive = false;

    #[ORM\Column(name: 'sos_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sosAt = null;

    #[ORM\Column(name: 'last_latitude', type: Types::FLOAT, nullable: true)]
    private ?float $lastLatitude = null;

    #[ORM\Column(name: 'last_longitude', type: Types::FLOAT, nullable: true)]
    private ?float $lastLongitude = null;

    #[ORM\Column(name: 'last_position_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastPositionAt = null;

    // Set when the app backgrounds without a matching EcoAppEvent::$returnedAt yet - null again
    // as soon as it comes back to the foreground. Drives the live "hors app" status on 1h/4d;
    // the actual logged duration lives on the EcoAppEvent row itself.
    #[ORM\Column(name: 'app_left_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appLeftAt = null;

    #[ORM\Column(name: 'joined_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    /** @var Collection<int, EcoCheckpointScan> */
    #[ORM\OneToMany(mappedBy: 'runner', targetEntity: EcoCheckpointScan::class)]
    #[ORM\OrderBy(['scannedAt' => 'ASC'])]
    private Collection $scans;

    public function __construct(EcoCourse $course, string $pseudo, string $joinToken)
    {
        $this->course = $course;
        $this->pseudo = $pseudo;
        $this->joinToken = $joinToken;
        $this->scans = new ArrayCollection();
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCourse(): ?EcoCourse
    {
        return $this->course;
    }

    public function getTeam(): ?EcoTeam
    {
        return $this->team;
    }

    public function setTeam(?EcoTeam $team): static
    {
        $this->team = $team;

        return $this;
    }

    public function getPseudo(): ?string
    {
        return $this->pseudo;
    }

    public function getJoinToken(): ?string
    {
        return $this->joinToken;
    }

    public function getStatus(): EcoRunnerStatus
    {
        return $this->status;
    }

    public function setStatus(EcoRunnerStatus $status): static
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

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getScoreValue(): ?int
    {
        return $this->scoreValue;
    }

    public function setScoreValue(?int $scoreValue): static
    {
        $this->scoreValue = $scoreValue;

        return $this;
    }

    public function isSosActive(): bool
    {
        return $this->sosActive;
    }

    public function getSosAt(): ?\DateTimeImmutable
    {
        return $this->sosAt;
    }

    public function triggerSos(\DateTimeImmutable $at): static
    {
        $this->sosActive = true;
        $this->sosAt = $at;

        return $this;
    }

    public function clearSos(): static
    {
        $this->sosActive = false;

        return $this;
    }

    public function getLastLatitude(): ?float
    {
        return $this->lastLatitude;
    }

    public function getLastLongitude(): ?float
    {
        return $this->lastLongitude;
    }

    public function getLastPositionAt(): ?\DateTimeImmutable
    {
        return $this->lastPositionAt;
    }

    public function updateLastPosition(float $latitude, float $longitude, \DateTimeImmutable $at): static
    {
        $this->lastLatitude = $latitude;
        $this->lastLongitude = $longitude;
        $this->lastPositionAt = $at;

        return $this;
    }

    public function getAppLeftAt(): ?\DateTimeImmutable
    {
        return $this->appLeftAt;
    }

    public function setAppLeftAt(?\DateTimeImmutable $appLeftAt): static
    {
        $this->appLeftAt = $appLeftAt;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /** @return Collection<int, EcoCheckpointScan> */
    public function getScans(): Collection
    {
        return $this->scans;
    }
}
