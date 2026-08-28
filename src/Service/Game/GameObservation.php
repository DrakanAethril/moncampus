<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * What an administrator needs to judge a barème they cannot judge from the barème itself: what it
 * actually paid, to whom, and how far that puts a class from the six thresholds.
 *
 * @phpstan-type ObservationRow array{student: \App\Entity\User, index: int, standing: GameStanding, windowPoints: int, cursusPoints: int, level: int, discreet: bool}
 * @phpstan-type RuleRow array{value: GameRuleValue, lines: int, points: int, share: float, tunable: bool}
 * @phpstan-type LevelRow array{level: GameLevel, reached: int, share: float, months: float|null}
 */
final readonly class GameObservation
{
    /**
     * @param list<ObservationRow> $rows      one per student of the class, best index first
     * @param list<RuleRow>        $rules     the whole barème, including the rules that paid nothing
     * @param list<LevelRow>       $levels    the six thresholds, against the cursus totals
     * @param int                  $pace      points a median student earns over 30 days at the window's rate
     * @param int                  $daysSpent days of the window already elapsed - what $pace is extrapolated from
     */
    public function __construct(
        public array $rows,
        public array $rules,
        public array $levels,
        public int $windowPoints,
        public int $pace,
        public int $daysSpent,
    ) {
    }

    public function studentCount(): int
    {
        return \count($this->rows);
    }

    /** How many of them have been credited anything at all over the window. */
    public function creditedCount(): int
    {
        return \count(array_filter($this->rows, static fn (array $row): bool => 0 !== $row['windowPoints']));
    }
}
