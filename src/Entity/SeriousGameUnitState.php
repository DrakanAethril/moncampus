<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameBrightness;
use App\Enum\SeriousGameTicketStatus;
use App\Repository\SeriousGameUnitStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a player stands on one unit of a game - in L'Observatoire, one star
 * (design/validated/serious-games.md §4).
 *
 * `$best` is the best series out of 12, null when the unit was never played to the end. **It only
 * goes up** (recordSeries()): neither time nor a worse series takes anything away, and the
 * brightness the screens draw is read from it (SeriousGameBrightness::fromBest()).
 *
 * **In a scenario** (design/validated/serious-games-neko-con.md §3.4) the unit is a ticket: `$best`
 * holds its best stars (1 to 3) and `$firstLitAt` the date it was first closed, both kept across a
 * « Nouvelle partie »; `$status`, `$stepId`, `$guideStep`, `$hints` and `$scenarioVersion` say where
 * it stands in the current game, and are null for the series. A ticket is on the board when it has
 * a status.
 */
#[ORM\Entity(repositoryClass: SeriousGameUnitStateRepository::class)]
#[ORM\Table(name: 'serious_game_unit_state')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_unit_state', columns: ['player_id', 'game_id', 'unit_code'])]
class SeriousGameUnitState
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

    #[ORM\Column(name: 'unit_code', length: 40)]
    private string $unitCode;

    #[ORM\Column(name: 'best', type: Types::SMALLINT, nullable: true)]
    private ?int $best = null;

    #[ORM\Column(name: 'series_count')]
    private int $seriesCount = 0;

    #[ORM\Column(name: 'first_lit_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstLitAt = null;

    #[ORM\Column(name: 'status', length: 20, nullable: true, enumType: SeriousGameTicketStatus::class)]
    private ?SeriousGameTicketStatus $status = null;

    /** The step in progress, by its identifier (Scenario::ALL_STEPS_DONE once all are answered). */
    #[ORM\Column(name: 'step_id', length: 40, nullable: true)]
    private ?string $stepId = null;

    /** How many of Inès' guided questions are answered right. */
    #[ORM\Column(name: 'guide_step', type: Types::SMALLINT, nullable: true)]
    private ?int $guideStep = null;

    /** How many rungs of Inès' help were asked for. */
    #[ORM\Column(name: 'hints', type: Types::SMALLINT, nullable: true)]
    private ?int $hints = null;

    #[ORM\Column(name: 'scenario_version', nullable: true)]
    private ?int $scenarioVersion = null;

    public function __construct(User $player, SeriousGame $game, string $unitCode)
    {
        $this->player = $player;
        $this->game = $game;
        $this->unitCode = $unitCode;
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

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getBest(): ?int
    {
        return $this->best;
    }

    public function getSeriesCount(): int
    {
        return $this->seriesCount;
    }

    public function getFirstLitAt(): ?\DateTimeImmutable
    {
        return $this->firstLitAt;
    }

    public function isLit(): bool
    {
        return null !== SeriousGameBrightness::fromBest($this->best);
    }

    public function getBrightness(): ?SeriousGameBrightness
    {
        return SeriousGameBrightness::fromBest($this->best);
    }

    /**
     * A series played to the end. The best only moves up; the first time the unit lights is
     * stamped once.
     *
     * @return bool whether this series lit the unit for the first time
     */
    public function recordSeries(int $score): bool
    {
        ++$this->seriesCount;
        $wasLit = $this->isLit();
        $this->best = max($this->best ?? 0, $score);

        if (!$wasLit && $this->isLit()) {
            $this->firstLitAt = new \DateTimeImmutable();

            return true;
        }

        return false;
    }

    public function getStatus(): ?SeriousGameTicketStatus
    {
        return $this->status;
    }

    public function isOnTheBoard(): bool
    {
        return null !== $this->status;
    }

    public function isClosed(): bool
    {
        return true === $this->status?->isClosed();
    }

    public function getStepId(): ?string
    {
        return $this->stepId;
    }

    public function getGuideStep(): int
    {
        return $this->guideStep ?? 0;
    }

    public function getHints(): int
    {
        return $this->hints ?? 0;
    }

    public function getScenarioVersion(): ?int
    {
        return $this->scenarioVersion;
    }

    /** The ticket comes onto the board. */
    public function putOnTheBoard(): static
    {
        $this->status = SeriousGameTicketStatus::Todo;
        $this->stepId = null;
        $this->guideStep = 0;
        $this->hints = 0;
        $this->scenarioVersion = null;

        return $this;
    }

    /** The ticket is opened for the first time in this game: it keeps the scenario's version. */
    public function startTicket(int $scenarioVersion, string $firstStepId): static
    {
        if (SeriousGameTicketStatus::Todo === $this->status) {
            $this->status = SeriousGameTicketStatus::Doing;
            $this->stepId = $firstStepId;
            $this->scenarioVersion = $scenarioVersion;
        }

        return $this;
    }

    public function moveToStep(string $stepId): static
    {
        $this->stepId = $stepId;

        return $this;
    }

    public function passGuideStep(): static
    {
        $this->guideStep = $this->getGuideStep() + 1;

        return $this;
    }

    public function askHint(): static
    {
        $this->hints = $this->getHints() + 1;

        return $this;
    }

    /**
     * The ticket is closed with its stars. The best stars only move up, and the first closing is
     * stamped once: a new game does not take them back.
     */
    public function closeTicket(bool $reassigned, int $stars): static
    {
        $this->status = $reassigned ? SeriousGameTicketStatus::Reassigned : SeriousGameTicketStatus::Done;
        $this->stepId = null;
        ++$this->seriesCount;
        $this->best = max($this->best ?? 0, $stars);
        $this->firstLitAt ??= new \DateTimeImmutable();

        return $this;
    }

    /** « Nouvelle partie »: the ticket leaves the board; its best stars and first closing stay. */
    public function resetForNewGame(): static
    {
        $this->status = null;
        $this->stepId = null;
        $this->guideStep = null;
        $this->hints = null;
        $this->scenarioVersion = null;

        return $this;
    }
}
