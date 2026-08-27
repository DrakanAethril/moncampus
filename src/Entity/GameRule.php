<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A formation's deviation from App\Service\Game\GameRuleCatalog.
 *
 * One row per formation and per rule, and **no period**: retuning a rule is a decision about a
 * class, not about a term, and versioning it by period meant a team could not change a value without
 * first knowing which period they were in - which is exactly the coupling that made the whole area
 * unusable outside the calendar.
 *
 * A month already closed keeps what it was played under all the same, because what is frozen is
 * the **result**: App\Entity\GameMonthScore is written once and never recomputed (§6), so moving a
 * value today cannot move January's ranking.
 *
 * Absence is the normal state: a program that has never retuned a rule has no row at all and plays
 * the catalogue, exactly as an untouched (feature, role) pair falls back on
 * App\Enum\Feature::defaultForRoles().
 */
#[ORM\Entity(repositoryClass: GameRuleRepository::class)]
#[ORM\Table(name: 'game_rule')]
#[ORM\UniqueConstraint(name: 'uniq_game_rule', columns: ['program_id', 'code'])]
class GameRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\Column(length: 60)]
    #[Assert\Length(max: 60)]
    private string $code;

    #[ORM\Column]
    #[Assert\Range(min: -200, max: 200)]
    private int $points;

    #[ORM\Column(name: 'weekly_cap', nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    private ?int $weeklyCap = null;

    #[ORM\Column]
    private bool $enabled = true;

    public function __construct(Program $program, string $code, int $points)
    {
        $this->program = $program;
        $this->code = $code;
        $this->points = $points;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getPoints(): int
    {
        return $this->points;
    }

    public function setPoints(int $points): static
    {
        $this->points = $points;

        return $this;
    }

    public function getWeeklyCap(): ?int
    {
        return $this->weeklyCap;
    }

    public function setWeeklyCap(?int $weeklyCap): static
    {
        $this->weeklyCap = $weeklyCap;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }
}
