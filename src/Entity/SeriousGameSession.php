<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One go at a game: a series on a unit, the final test, or a comet studied
 * (design/validated/serious-games.md §4).
 *
 * `$plan` is the list of questions decided when the session starts - for each position, the unit,
 * the template version and the seed. Deciding them up front is what makes a series of 12 free of
 * duplicates and the final test a fixed 3 + 3 + 2 + 2 + 2; the answers are then written position
 * by position in SeriousGameAnswer. The statement is never stored: seed and version suffice.
 *
 * A session that is never ended simply stays open - nothing in the game depends on time.
 *
 * @phpstan-type PlannedQuestion array{unit: string, version: int, seed: int}
 */
#[ORM\Entity(repositoryClass: SeriousGameSessionRepository::class)]
#[ORM\Table(name: 'serious_game_session')]
#[ORM\Index(name: 'idx_serious_game_session_player', columns: ['player_id', 'game_id', 'started_at'])]
class SeriousGameSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'player_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $player = null;

    #[ORM\ManyToOne(targetEntity: SeriousGame::class)]
    #[ORM\JoinColumn(name: 'game_id', nullable: false, onDelete: 'CASCADE')]
    private ?SeriousGame $game = null;

    #[ORM\Column(name: 'mode', length: 20, enumType: SeriousGameSessionMode::class)]
    private SeriousGameSessionMode $mode;

    /** The unit of a series; for a comet, the unit its error belongs to; null for the final test. */
    #[ORM\Column(name: 'unit_code', length: 40, nullable: true)]
    private ?string $unitCode = null;

    #[ORM\Column(name: 'misconception', length: 80, nullable: true)]
    private ?string $misconception = null;

    #[ORM\Column(name: 'size', type: Types::SMALLINT)]
    private int $size;

    /** @var list<PlannedQuestion> */
    #[ORM\Column(name: 'plan', type: Types::JSON)]
    private array $plan;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'ended_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(name: 'score', type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(name: 'time_limit_seconds', nullable: true)]
    private ?int $timeLimitSeconds = null;

    #[ORM\Column(name: 'timed_out')]
    private bool $timedOut = false;

    /** @var Collection<int, SeriousGameAnswer> */
    #[ORM\OneToMany(targetEntity: SeriousGameAnswer::class, mappedBy: 'session')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $answers;

    /**
     * @param list<PlannedQuestion> $plan
     */
    public function __construct(User $player, SeriousGame $game, SeriousGameSessionMode $mode, array $plan)
    {
        $this->player = $player;
        $this->game = $game;
        $this->mode = $mode;
        $this->plan = $plan;
        $this->size = \count($plan);
        $this->startedAt = new \DateTimeImmutable();
        $this->answers = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): User
    {
        \assert(null !== $this->player);

        return $this->player;
    }

    public function getGame(): SeriousGame
    {
        \assert(null !== $this->game);

        return $this->game;
    }

    public function getMode(): SeriousGameSessionMode
    {
        return $this->mode;
    }

    public function isFinal(): bool
    {
        return SeriousGameSessionMode::Final === $this->mode;
    }

    public function getUnitCode(): ?string
    {
        return $this->unitCode;
    }

    public function setUnitCode(?string $unitCode): static
    {
        $this->unitCode = $unitCode;

        return $this;
    }

    public function getMisconception(): ?string
    {
        return $this->misconception;
    }

    public function setMisconception(?string $misconception): static
    {
        $this->misconception = $misconception;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    /** @return list<PlannedQuestion> */
    public function getPlan(): array
    {
        return $this->plan;
    }

    /** @return PlannedQuestion|null */
    public function plannedAt(int $position): ?array
    {
        return $this->plan[$position] ?? null;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isEnded(): bool
    {
        return null !== $this->endedAt;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function end(int $score, bool $timedOut = false): static
    {
        $this->endedAt = new \DateTimeImmutable();
        $this->score = $score;
        $this->timedOut = $timedOut;

        return $this;
    }

    /**
     * Closes a ticket's session that did not close the ticket - an opening left for another one,
     * or one a « Nouvelle partie » leaves behind. It ends with no score: only the opening that
     * closes the ticket carries its stars.
     */
    public function abandon(): static
    {
        $this->endedAt ??= new \DateTimeImmutable();

        return $this;
    }

    public function getTimeLimitSeconds(): ?int
    {
        return $this->timeLimitSeconds;
    }

    public function setTimeLimitSeconds(?int $timeLimitSeconds): static
    {
        $this->timeLimitSeconds = $timeLimitSeconds;

        return $this;
    }

    public function isTimedOut(): bool
    {
        return $this->timedOut;
    }

    /** The moment the clock of a timed session runs out, or null when nothing is timed. */
    public function getDeadline(): ?\DateTimeImmutable
    {
        return null === $this->timeLimitSeconds ? null : $this->startedAt->modify('+'.$this->timeLimitSeconds.' seconds');
    }

    /** @return Collection<int, SeriousGameAnswer> */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }
}
