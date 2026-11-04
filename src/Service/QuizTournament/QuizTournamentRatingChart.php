<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * The geometry of « Ta cote, duel après duel » (H-mes-duels), as a pure function: a single line over
 * the ratings in a fixed viewBox (WIDTH × HEIGHT), the start (1000) on the left edge and the last
 * duel on the right, with round horizontal gridlines - 25 points apart, 50 or 100 when the range
 * is wider - that frame every point. The template draws it as inline SVG and
 * quiz_tournament_rating_chart_controller.js adds the hover readout.
 */
final class QuizTournamentRatingChart
{
    public const int WIDTH = 348;
    public const int HEIGHT = 172;
    public const int LEFT = 40;
    public const int RIGHT = 336;
    public const int TOP = 12;
    public const int BOTTOM = 146;

    /** At most this many gridlines: the step widens until they fit. */
    private const int MAX_LINES = 8;

    /**
     * @param list<int> $ratings 1000 first, then the rating after each duel
     *
     * @return array{points: list<array{x: int|float, y: float, rating: int, duel: int}>, grid: list<array{y: float, value: int}>, polyline: string}
     */
    public static function of(array $ratings): array
    {
        if ([] === $ratings) {
            $ratings = [QuizTournamentElo::START];
        }

        $min = min($ratings);
        $max = max($ratings);
        $step = 25;
        while (true) {
            $low = (int) (floor($min / $step) * $step);
            $high = (int) (ceil($max / $step) * $step);
            if ($high === $low) {
                $low -= $step;
                $high += $step;
            }
            if (($high - $low) / $step + 1 <= self::MAX_LINES || $step >= 1000) {
                break;
            }
            $step = 25 === $step ? 50 : $step * 2;
        }

        $y = static fn (int $value): float => round(self::BOTTOM - ($value - $low) / ($high - $low) * (self::BOTTOM - self::TOP), 1);
        $last = \count($ratings) - 1;
        $points = [];
        foreach ($ratings as $index => $rating) {
            $x = 0 === $last ? self::LEFT : round(self::LEFT + $index / $last * (self::RIGHT - self::LEFT), 1);
            if (0.0 === fmod((float) $x, 1.0)) {
                $x = (int) $x;
            }
            $points[] = ['x' => $x, 'y' => $y($rating), 'rating' => $rating, 'duel' => $index];
        }

        $grid = [];
        for ($value = $low; $value <= $high; $value += $step) {
            $grid[] = ['y' => $y($value), 'value' => $value];
        }

        return [
            'points' => $points,
            'grid' => $grid,
            'polyline' => implode(' ', array_map(static fn (array $point): string => $point['x'].','.$point['y'], $points)),
        ];
    }
}
