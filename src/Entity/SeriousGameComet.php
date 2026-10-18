<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SeriousGameCometState;
use App\Repository\SeriousGameCometRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A comet: one typical error the player made twice among their last 30 answers in the game
 * (design/validated/serious-games.md §1.6, §6.2).
 *
 * It is studied - two sentences, three questions - and is « élucidée » at 2 right out of 3; below,
 * it « reste en vue » and can be studied again. Nothing is ever lost and nothing depends on time.
 * An error solved once that comes back opens a **new** row: the history of a comet is kept.
 */
#[ORM\Entity(repositoryClass: SeriousGameCometRepository::class)]
#[ORM\Table(name: 'serious_game_comet')]
#[ORM\Index(name: 'idx_serious_game_comet_player', columns: ['player_id', 'game_id', 'state'])]
class SeriousGameComet
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

    #[ORM\Column(name: 'misconception', length: 80)]
    private string $misconception;

    #[ORM\Column(name: 'unit_code', length: 40)]
    private string $unitCode;

    #[ORM\Column(name: 'state', length: 20, enumType: SeriousGameCometState::class)]
    private SeriousGameCometState $state = SeriousGameCometState::InView;

    #[ORM\Column(name: 'appeared_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $appearedAt;

    #[ORM\Column(name: 'solved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $solvedAt = null;

    #[ORM\Column(name: 'attempts', type: Types::SMALLINT)]
    private int $attempts = 0;

    public function __construct(User $player, SeriousGame $game, string $misconception, string $unitCode)
    {
        $this->player = $player;
        $this->game = $game;
        $this->misconception = $misconception;
        $this->unitCode = $unitCode;
        $this->appearedAt = new \DateTimeImmutable();
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

    public function getMisconception(): string
    {
        return $this->misconception;
    }

    public function getUnitCode(): string
    {
        return $this->unitCode;
    }

    public function getState(): SeriousGameCometState
    {
        return $this->state;
    }

    public function isInView(): bool
    {
        return SeriousGameCometState::InView === $this->state;
    }

    public function getAppearedAt(): \DateTimeImmutable
    {
        return $this->appearedAt;
    }

    public function getSolvedAt(): ?\DateTimeImmutable
    {
        return $this->solvedAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** One study played to the end: solved, or still in view - never anything worse. */
    public function recordStudy(bool $solved): static
    {
        ++$this->attempts;

        if ($solved && $this->isInView()) {
            $this->state = SeriousGameCometState::Solved;
            $this->solvedAt = new \DateTimeImmutable();
        }

        return $this;
    }
}
