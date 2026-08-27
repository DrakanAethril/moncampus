<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GameMonthScoreRepository;
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
#[ORM\Entity(repositoryClass: GameMonthScoreRepository::class)]
#[ORM\Table(name: 'game_month_score')]
#[ORM\UniqueConstraint(name: 'uniq_game_month_score', columns: ['student_id', 'month_key', 'program_id'])]
class GameMonthScore
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

    /** `YYYY-MM` - sortable as a string, which is what makes a plain column a calendar. */
    #[ORM\Column(name: 'month_key', length: 7)]
    private string $monthKey;

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

    /** The top-three bonus paid at the end of the month - 20, 10, 5, or nothing at all. */
    #[ORM\Column(name: 'bonus_awarded')]
    private int $bonusAwarded = 0;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $closedAt;

    public function __construct(User $student, Program $program, string $monthKey, int $indexValue)
    {
        $this->student = $student;
        $this->program = $program;
        $this->monthKey = $monthKey;
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

    public function getMonthKey(): string
    {
        return $this->monthKey;
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

    public function getBonusAwarded(): int
    {
        return $this->bonusAwarded;
    }

    public function setBonusAwarded(int $bonus): static
    {
        $this->bonusAwarded = $bonus;

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
