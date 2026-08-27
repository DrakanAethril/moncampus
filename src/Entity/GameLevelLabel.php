<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameTrack;
use App\Repository\GameLevelLabelRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The wording of one level in one filière - « Chasseur·se de bugs », « Sentinelle du réseau »
 * (design's screen 3, /settings/game/levels).
 *
 * Only the **wording** is editable. The XP thresholds are common to the whole establishment and
 * live in App\Service\Game\GameLevels: a level is what the avatar carries on every screen of the
 * application, and a threshold that moved from one formation to the next would make the ring mean
 * nothing.
 *
 * A missing row falls back on a generic wording rather than on an empty cell, which is why nothing
 * here is seeded as mandatory.
 */
#[ORM\Entity(repositoryClass: GameLevelLabelRepository::class)]
#[ORM\Table(name: 'game_level_label')]
#[ORM\UniqueConstraint(name: 'uniq_game_level_label', columns: ['track', 'level'])]
class GameLevelLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: GameTrack::class)]
    private GameTrack $track;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 6)]
    private int $level;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $label;

    public function __construct(GameTrack $track, int $level, string $label)
    {
        $this->track = $track;
        $this->level = $level;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrack(): GameTrack
    {
        return $this->track;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
