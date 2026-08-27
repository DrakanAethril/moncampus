<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The durable half of a student's game: the XP of the whole cursus, the level it reaches, the title
 * they display and whether they have stepped out of the rankings.
 *
 * One row per student, across every formation they pass through. **XP is never reset** - not by a
 * malus, not by a change of school year (§5.6): a level is what the avatar carries everywhere, and
 * a level that could be lost would make constancy worth nothing.
 *
 * $discreetSince is what enforces the other half of the discreet mode: leaving is immediate,
 * **coming back only takes effect on the next period** (§4, decision 8). Without that date the
 * switch becomes tactical - out on the eve of a closure, back in the morning after - and the
 * ranking stops meaning anything.
 */
#[ORM\Entity(repositoryClass: GameProfileRepository::class)]
#[ORM\Table(name: 'game_profile')]
#[ORM\UniqueConstraint(name: 'uniq_game_profile_student', columns: ['student_id'])]
class GameProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\Column(name: 'xp_total')]
    private int $xpTotal = 0;

    #[ORM\Column]
    private int $level = 1;

    /** Chosen among the titles already unlocked, from the silver tier on; null falls back on the current level's. */
    #[ORM\Column(name: 'displayed_title', length: 120, nullable: true)]
    private ?string $displayedTitle = null;

    #[ORM\Column]
    private bool $discreet = false;

    #[ORM\Column(name: 'discreet_since', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $discreetSince = null;

    /** When discretion was lifted - the person is back **from the period that starts after this**. */
    #[ORM\Column(name: 'discreet_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $discreetUntil = null;

    public function __construct(User $student)
    {
        $this->student = $student;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getXpTotal(): int
    {
        return $this->xpTotal;
    }

    /** The only way XP moves, and it only ever goes up. */
    public function addXp(int $xp): static
    {
        $this->xpTotal += max(0, $xp);

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        // Never downwards: see the class docblock.
        $this->level = max($this->level, $level);

        return $this;
    }

    public function getDisplayedTitle(): ?string
    {
        return $this->displayedTitle;
    }

    public function setDisplayedTitle(?string $displayedTitle): static
    {
        $this->displayedTitle = $displayedTitle;

        return $this;
    }

    public function isDiscreet(): bool
    {
        return $this->discreet;
    }

    public function getDiscreetSince(): ?\DateTimeImmutable
    {
        return $this->discreetSince;
    }

    public function getDiscreetUntil(): ?\DateTimeImmutable
    {
        return $this->discreetUntil;
    }

    /**
     * Step out, immediately; or ask to come back, which the next period grants.
     *
     * Coming back writes $discreetUntil and leaves $discreet standing - the flag is lowered by
     * App\Service\Game\GamePeriodCloser when the period it was raised in ends, never by the click.
     */
    public function setDiscreet(bool $discreet, ?\DateTimeImmutable $at = null): static
    {
        $at ??= new \DateTimeImmutable();

        if ($discreet) {
            if (!$this->discreet) {
                $this->discreetSince = $at;
            }
            $this->discreet = true;
            $this->discreetUntil = null;

            return $this;
        }

        if ($this->discreet) {
            $this->discreetUntil = $at;
        }

        return $this;
    }

    /** Called at closure: the request to come back takes effect now, and not a day earlier. */
    public function applyPendingReturn(): static
    {
        if (null !== $this->discreetUntil) {
            $this->discreet = false;
            $this->discreetSince = null;
            $this->discreetUntil = null;
        }

        return $this;
    }

    /** Whether a return has been asked for and is waiting for the closure. */
    public function isReturningNextPeriod(): bool
    {
        return $this->discreet && null !== $this->discreetUntil;
    }
}
