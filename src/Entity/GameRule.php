<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A formation's deviation from App\Service\Game\GameRuleCatalog, for one period.
 *
 * Rows are **versioned by period** deliberately (§6): the barème is adjustable, so it will move,
 * and a period already closed keeps the rules it was played under - what is frozen in
 * App\Entity\GamePeriodScore is the result, and these rows are what produced it. A period still
 * running follows the new value from the moment it is saved (§9).
 *
 * Absence is the normal state: a program that has never retuned a rule has no row at all and plays
 * the catalogue, exactly as an untouched (feature, role) pair falls back on
 * App\Enum\Feature::defaultForRoles().
 */
#[ORM\Entity(repositoryClass: GameRuleRepository::class)]
#[ORM\Table(name: 'game_rule')]
#[ORM\UniqueConstraint(name: 'uniq_game_rule', columns: ['program_id', 'period_id', 'code'])]
class GameRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private EvaluationPeriod $period;

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

    public function __construct(Program $program, EvaluationPeriod $period, string $code, int $points)
    {
        $this->program = $program;
        $this->period = $period;
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

    public function getPeriod(): EvaluationPeriod
    {
        return $this->period;
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
