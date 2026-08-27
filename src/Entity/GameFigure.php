<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameTrack;
use App\Repository\GameFigureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A historical figure of one filière, from which a student takes their pseudonym for a period
 * (§4, decision 8).
 *
 * « Anonyme #12 » was the alternative, and it was refused: cold, uninhabitable, and thirty of them
 * are needed at once. A surname is short, neutral, fits in a column - and it teaches something. Over
 * four semesters a student is offered twelve figures of their own field; the class shows thirty at a
 * time; the révélation turns the ranking into a wall of figures, each name given back to its person.
 *
 * $notice is one line on what the person actually did. **A wrong notice in a device that claims to be
 * pedagogical is worse than no notice at all**, which is why the seeded catalogue is a starting
 * point to be proof-read rather than a deliverable one - see $reviewed.
 */
#[ORM\Entity(repositoryClass: GameFigureRepository::class)]
#[ORM\Table(name: 'game_figure')]
#[ORM\Index(name: 'idx_game_figure_track', columns: ['track', 'active'])]
class GameFigure
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** What the ranking prints - the patronym alone. */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $surname;

    #[ORM\Column(name: 'full_name', length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $fullName;

    /** « 1815-1852 », « née en 1951 » - free text, because a life is not always two years. */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $dates = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $notice = null;

    #[ORM\Column(length: 10, enumType: GameTrack::class)]
    private GameTrack $track;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Whether a human has checked this entry.
     *
     * The seeded catalogue is an **amorce** - ten to fifteen names per filière, taken from the
     * design's own list - and it ships unreviewed on purpose: a name is easy, a correct one-line
     * notice is documentary work. The drawer does not care about this flag; the administration
     * screen and this docblock do, and the game should not be opened to a class before it reads
     * true everywhere.
     */
    #[ORM\Column]
    private bool $reviewed = false;

    public function __construct(GameTrack $track, string $surname, string $fullName)
    {
        $this->track = $track;
        $this->surname = $surname;
        $this->fullName = $fullName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): static
    {
        $this->surname = $surname;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getDates(): ?string
    {
        return $this->dates;
    }

    public function setDates(?string $dates): static
    {
        $this->dates = '' === $dates ? null : $dates;

        return $this;
    }

    public function getNotice(): ?string
    {
        return $this->notice;
    }

    public function setNotice(?string $notice): static
    {
        $this->notice = '' === $notice ? null : $notice;

        return $this;
    }

    public function getTrack(): GameTrack
    {
        return $this->track;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isReviewed(): bool
    {
        return $this->reviewed;
    }

    public function setReviewed(bool $reviewed): static
    {
        $this->reviewed = $reviewed;

        return $this;
    }

    /** The two initials the alias card draws in place of an avatar. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->fullName)) ?: [];
        $first = mb_substr((string) ($parts[0] ?? $this->surname), 0, 1);
        $last = mb_substr((string) (end($parts) ?: $this->surname), 0, 1);

        return mb_strtoupper($first.$last);
    }
}
