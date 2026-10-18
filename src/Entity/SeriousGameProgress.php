<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SeriousGameProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A player's presence in one game (design/validated/serious-games.md §4).
 *
 * `$data` is the game's **display state** only - which page of the story was read, which
 * constellation was looked at last - written by the game through `saveProgress()`. It never holds
 * the progress itself: what a player acquired is written by the server alone, in
 * SeriousGameUnitState, from answers it corrected.
 *
 * `$lastPlayedAt` is the date the 24-month retention of sessions and answers is read from
 * (App\Command\PurgePlatformActivityCommand), and the « inactifs depuis 14 jours » filter of the
 * reading - a reading, never a consequence for the player.
 */
#[ORM\Entity(repositoryClass: SeriousGameProgressRepository::class)]
#[ORM\Table(name: 'serious_game_progress')]
#[ORM\UniqueConstraint(name: 'uniq_serious_game_progress', columns: ['player_id', 'game_id'])]
#[ORM\Index(name: 'idx_serious_game_progress_played', columns: ['last_played_at'])]
class SeriousGameProgress
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

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'data', type: Types::JSON)]
    private array $data = [];

    #[ORM\Column(name: 'last_played_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastPlayedAt;

    public function __construct(User $player, SeriousGame $game)
    {
        $this->player = $player;
        $this->game = $game;
        $this->lastPlayedAt = new \DateTimeImmutable();
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

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function getLastPlayedAt(): \DateTimeImmutable
    {
        return $this->lastPlayedAt;
    }

    public function touch(): static
    {
        $this->lastPlayedAt = new \DateTimeImmutable();

        return $this;
    }
}
