<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameBrightness;
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
}
