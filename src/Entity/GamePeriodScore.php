<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GamePeriodScoreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The frozen snapshot of one student's period: **written once, never recomputed** (§6).
 *
 * The barème is adjustable, so it will change. If a closed period recomputed itself under today's
 * rules, January's ranking would move in June and the rewards granted on it would become
 * arguable - which is the one thing a game handing out places must not allow. What is stored is
 * therefore the *result*, not the ingredients: the index, the four rates, the rank, the XP paid.
 *
 * $rank is null for a student in discreet mode - they are scored and rewarded like everybody else,
 * they simply appear nowhere.
 */
#[ORM\Entity(repositoryClass: GamePeriodScoreRepository::class)]
#[ORM\Table(name: 'game_period_score')]
#[ORM\UniqueConstraint(name: 'uniq_game_period_score', columns: ['student_id', 'period_id', 'program_id'])]
class GamePeriodScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $student;

    #[ORM\ManyToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\ManyToOne(targetEntity: EvaluationPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private EvaluationPeriod $period;

    #[ORM\Column(name: 'index_value')]
    private int $indexValue;

    #[ORM\Column(name: 'rate_attendance', type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $rateAttendance = null;

    #[ORM\Column(name: 'rate_work', type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $rateWork = null;

    #[ORM\Column(name: 'rate_engagement', type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $rateEngagement = null;

    #[ORM\Column(name: 'rate_recognition', type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $rateRecognition = null;

    #[ORM\Column(name: '`rank`', nullable: true)]
    private ?int $rank = null;

    #[ORM\Column(name: 'xp_awarded')]
    private int $xpAwarded = 0;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    public function __construct(User $student, Program $program, EvaluationPeriod $period, int $indexValue)
    {
        $this->student = $student;
        $this->program = $program;
        $this->period = $period;
        $this->indexValue = $indexValue;
        $this->closedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStudent(): User
    {
        return $this->student;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    public function getPeriod(): EvaluationPeriod
    {
        return $this->period;
    }

    public function getIndexValue(): int
    {
        return $this->indexValue;
    }

    /** @param array<string, float> $rates keyed by App\Enum\GameFamily value; an absent family stays null */
    public function setRates(array $rates): static
    {
        $this->rateAttendance = $this->decimal($rates['attendance'] ?? null);
        $this->rateWork = $this->decimal($rates['work'] ?? null);
        $this->rateEngagement = $this->decimal($rates['engagement'] ?? null);
        $this->rateRecognition = $this->decimal($rates['recognition'] ?? null);

        return $this;
    }

    /** @return array<string, float|null> */
    public function getRates(): array
    {
        return [
            'attendance' => null === $this->rateAttendance ? null : (float) $this->rateAttendance,
            'work' => null === $this->rateWork ? null : (float) $this->rateWork,
            'engagement' => null === $this->rateEngagement ? null : (float) $this->rateEngagement,
            'recognition' => null === $this->rateRecognition ? null : (float) $this->rateRecognition,
        ];
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;

        return $this;
    }

    public function getXpAwarded(): int
    {
        return $this->xpAwarded;
    }

    public function setXpAwarded(int $xpAwarded): static
    {
        $this->xpAwarded = $xpAwarded;

        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    private function decimal(?float $rate): ?string
    {
        return null === $rate ? null : number_format($rate, 4, '.', '');
    }
}
