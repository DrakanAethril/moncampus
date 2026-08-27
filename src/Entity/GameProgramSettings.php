<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GameAttendanceStep;
use App\Enum\GameFamily;
use App\Enum\GameTeamMode;
use App\Repository\GameProgramSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One formation's game settings - the single screen an equipe should ever have to open
 * (design/validated/gamification.md screen 14).
 *
 * The four weights are **the real barème** (§2). Setting 30/30/25/15 is a pedagogical statement
 * readable in one line, where tuning forty rule values is readable nowhere; that is why they sit
 * here rather than among App\Entity\GameRule's rows, and why they are what the screen leads with.
 *
 * The gesture envelope and the ±60 per-teacher bound lived here until 2026-08-28 and are gone: a
 * quota placed between a teacher and their own judgement was removed on request, and a setting that
 * no longer governs anything is worse than no setting at all.
 *
 * A row exists only once a formation has saved something. Everything is defaulted here, so a
 * program that has never opened the screen plays a complete game - and
 * App\Entity\Program::$gameEnabled, not this entity, is what says whether it plays at all.
 */
#[ORM\Entity(repositoryClass: GameProgramSettingsRepository::class)]
#[ORM\Table(name: 'game_program_settings')]
#[ORM\UniqueConstraint(name: 'uniq_game_program_settings_program', columns: ['program_id'])]
class GameProgramSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Program::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Program $program;

    #[ORM\Column(name: 'weight_attendance')]
    #[Assert\Range(min: 0, max: 100)]
    private int $weightAttendance = 30;

    #[ORM\Column(name: 'weight_work')]
    #[Assert\Range(min: 0, max: 100)]
    private int $weightWork = 30;

    #[ORM\Column(name: 'weight_engagement')]
    #[Assert\Range(min: 0, max: 100)]
    private int $weightEngagement = 25;

    #[ORM\Column(name: 'weight_recognition')]
    #[Assert\Range(min: 0, max: 100)]
    private int $weightRecognition = 15;

    /** The flat possible of the volunteering family (§2). Zero takes the family out of the index. */
    #[ORM\Column(name: 'engagement_cap')]
    #[Assert\Range(min: 0, max: 5000)]
    private int $engagementCap = 250;

    #[ORM\Column(name: 'recognition_cap')]
    #[Assert\Range(min: 0, max: 5000)]
    private int $recognitionCap = 150;

    #[ORM\Column(name: 'attendance_step', length: 10, enumType: GameAttendanceStep::class)]
    private GameAttendanceStep $attendanceStep = GameAttendanceStep::Week;

    /** The ceiling of the consecutive-net bonus, +40 in the design. */
    #[ORM\Column(name: 'attendance_streak_cap')]
    #[Assert\Range(min: 0, max: 500)]
    private int $attendanceStreakCap = 40;

    #[ORM\Column(name: 'threshold_bronze')]
    #[Assert\Range(min: 0, max: 100)]
    private int $thresholdBronze = 40;

    #[ORM\Column(name: 'threshold_silver')]
    #[Assert\Range(min: 0, max: 100)]
    private int $thresholdSilver = 65;

    #[ORM\Column(name: 'threshold_gold')]
    #[Assert\Range(min: 0, max: 100)]
    private int $thresholdGold = 85;

    /** The index every member of a team must clear for all of them to be paid (§4, decision 7). */
    #[ORM\Column(name: 'team_threshold')]
    #[Assert\Range(min: 0, max: 100)]
    private int $teamThreshold = 50;

    #[ORM\Column(name: 'team_mode', length: 10, enumType: GameTeamMode::class)]
    private GameTeamMode $teamMode = GameTeamMode::Period;

    #[ORM\Column(name: 'ranking_enabled')]
    private bool $rankingEnabled = true;

    /**
     * The months of the year this formation wants a ranking for, as numbers 1-12.
     *
     * All twelve by default. A school that does not want July and August simply unticks them: an
     * unranked month is still played - points are still earned and still count towards the year -
     * it is only never closed, never ranked, and never pays a podium.
     *
     * The docblock says `array-key` rather than `list` deliberately: this is a JSON column, and what
     * comes back out of it is whatever was written - the `array_values()` in the getter is the
     * normalisation, not a redundant call.
     *
     * @var array<array-key, int>
     */
    #[ORM\Column(name: 'ranked_months')]
    private array $rankedMonths = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

    #[ORM\Column(name: 'alias_enabled')]
    private bool $aliasEnabled = true;

    /** The one malus of the whole system (§4, decision 6). A formation may refuse it; none may add another. */
    #[ORM\Column(name: 'malus_enabled')]
    private bool $malusEnabled = true;

    public function __construct(Program $program)
    {
        $this->program = $program;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): Program
    {
        return $this->program;
    }

    /** @return array<string, int> keyed by App\Enum\GameFamily value - what GameScoreCalculator takes */
    public function weights(): array
    {
        return [
            GameFamily::Attendance->value => $this->weightAttendance,
            GameFamily::Work->value => $this->weightWork,
            GameFamily::Engagement->value => $this->weightEngagement,
            GameFamily::Recognition->value => $this->weightRecognition,
        ];
    }

    public function getWeightAttendance(): int
    {
        return $this->weightAttendance;
    }

    public function setWeightAttendance(int $weight): static
    {
        $this->weightAttendance = $weight;

        return $this;
    }

    public function getWeightWork(): int
    {
        return $this->weightWork;
    }

    public function setWeightWork(int $weight): static
    {
        $this->weightWork = $weight;

        return $this;
    }

    public function getWeightEngagement(): int
    {
        return $this->weightEngagement;
    }

    public function setWeightEngagement(int $weight): static
    {
        $this->weightEngagement = $weight;

        return $this;
    }

    public function getWeightRecognition(): int
    {
        return $this->weightRecognition;
    }

    public function setWeightRecognition(int $weight): static
    {
        $this->weightRecognition = $weight;

        return $this;
    }

    public function getEngagementCap(): int
    {
        return $this->engagementCap;
    }

    public function setEngagementCap(int $cap): static
    {
        $this->engagementCap = $cap;

        return $this;
    }

    public function getRecognitionCap(): int
    {
        return $this->recognitionCap;
    }

    public function setRecognitionCap(int $cap): static
    {
        $this->recognitionCap = $cap;

        return $this;
    }

    public function getAttendanceStep(): GameAttendanceStep
    {
        return $this->attendanceStep;
    }

    public function setAttendanceStep(GameAttendanceStep $step): static
    {
        $this->attendanceStep = $step;

        return $this;
    }

    public function getAttendanceStreakCap(): int
    {
        return $this->attendanceStreakCap;
    }

    public function setAttendanceStreakCap(int $cap): static
    {
        $this->attendanceStreakCap = $cap;

        return $this;
    }

    public function getThresholdBronze(): int
    {
        return $this->thresholdBronze;
    }

    public function setThresholdBronze(int $threshold): static
    {
        $this->thresholdBronze = $threshold;

        return $this;
    }

    public function getThresholdSilver(): int
    {
        return $this->thresholdSilver;
    }

    public function setThresholdSilver(int $threshold): static
    {
        $this->thresholdSilver = $threshold;

        return $this;
    }

    public function getThresholdGold(): int
    {
        return $this->thresholdGold;
    }

    public function setThresholdGold(int $threshold): static
    {
        $this->thresholdGold = $threshold;

        return $this;
    }

    public function getTeamThreshold(): int
    {
        return $this->teamThreshold;
    }

    public function setTeamThreshold(int $threshold): static
    {
        $this->teamThreshold = $threshold;

        return $this;
    }

    public function getTeamMode(): GameTeamMode
    {
        return $this->teamMode;
    }

    public function setTeamMode(GameTeamMode $mode): static
    {
        $this->teamMode = $mode;

        return $this;
    }

    public function isRankingEnabled(): bool
    {
        return $this->rankingEnabled;
    }

    /** @return list<int> */
    public function getRankedMonths(): array
    {
        return array_values(array_map(intval(...), $this->rankedMonths));
    }

    /**
     * @param array<array-key, int> $months
     *
     * The `array_values()` is input normalisation on a public setter feeding a JSON column, and the
     * parameter is widened rather than the call dropped - the repository's own rule
     */
    public function setRankedMonths(array $months): static
    {
        $kept = array_unique(array_filter($months, static fn (int $month): bool => $month >= 1 && $month <= 12));
        sort($kept);
        $this->rankedMonths = $kept;

        return $this;
    }

    public function ranksMonth(int $month): bool
    {
        return \in_array($month, $this->getRankedMonths(), true);
    }

    public function setRankingEnabled(bool $enabled): static
    {
        $this->rankingEnabled = $enabled;

        return $this;
    }

    public function isAliasEnabled(): bool
    {
        return $this->aliasEnabled;
    }

    public function setAliasEnabled(bool $enabled): static
    {
        $this->aliasEnabled = $enabled;

        return $this;
    }

    public function isMalusEnabled(): bool
    {
        return $this->malusEnabled;
    }

    public function setMalusEnabled(bool $enabled): static
    {
        $this->malusEnabled = $enabled;

        return $this;
    }
}
