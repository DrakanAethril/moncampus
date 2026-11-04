<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * The tournament's rating (design/validated/quiz-tournament.md §6.1), as pure functions: the chess
 * formula, each tournament starting everybody at 1000.
 *
 * E = 1 / (1 + 10^((Rb − Ra) / 400)); new rating = R + K × (S − E), rounded to the integer; K = 40
 * for a player's first ten duels and 20 afterwards; S = 1 for more points, 0.5 for as many - speed
 * never decides.
 *
 * The caller updates both ratings in one transaction with both players locked, and keeps the
 * ratings before and after on the duel.
 */
final class QuizTournamentElo
{
    public const int START = 1000;
    public const int K_PLACEMENT = 40;
    public const int K_SETTLED = 20;
    public const int PLACEMENT_DUELS = 10;

    /** Two scores closer than this are equal: partial credit sums floats. */
    private const float SCORE_EPSILON = 0.001;

    public static function kFactor(int $duelsPlayedBefore): int
    {
        return $duelsPlayedBefore < self::PLACEMENT_DUELS ? self::K_PLACEMENT : self::K_SETTLED;
    }

    public static function expectedScore(int $rating, int $opponentRating): float
    {
        return 1 / (1 + 10 ** (($opponentRating - $rating) / 400));
    }

    /** @param float $score 1, 0.5 or 0 - see outcome() */
    public static function newRating(int $rating, int $opponentRating, float $score, int $duelsPlayedBefore): int
    {
        return (int) round($rating + self::kFactor($duelsPlayedBefore) * ($score - self::expectedScore($rating, $opponentRating)));
    }

    /** The result of a duel for the player who scored $points: 1, 0.5 or 0. */
    public static function outcome(float $points, float $opponentPoints): float
    {
        if (abs($points - $opponentPoints) < self::SCORE_EPSILON) {
            return 0.5;
        }

        return $points > $opponentPoints ? 1.0 : 0.0;
    }

    /**
     * Both new ratings of a finished duel.
     *
     * @return array{0: int, 1: int} [player A, player B]
     */
    public static function rate(int $ratingA, int $ratingB, float $pointsA, float $pointsB, int $duelsBeforeA, int $duelsBeforeB): array
    {
        return [
            self::newRating($ratingA, $ratingB, self::outcome($pointsA, $pointsB), $duelsBeforeA),
            self::newRating($ratingB, $ratingA, self::outcome($pointsB, $pointsA), $duelsBeforeB),
        ];
    }

    /**
     * What a duel would bring or cost the player, on today's ratings - the « enjeu » the Défier sheet
     * shows before the challenge is sent.
     *
     * @return array{win: int, draw: int, loss: int} rating differences
     */
    public static function stakes(int $rating, int $opponentRating, int $duelsPlayedBefore): array
    {
        return [
            'win' => self::newRating($rating, $opponentRating, 1.0, $duelsPlayedBefore) - $rating,
            'draw' => self::newRating($rating, $opponentRating, 0.5, $duelsPlayedBefore) - $rating,
            'loss' => self::newRating($rating, $opponentRating, 0.0, $duelsPlayedBefore) - $rating,
        ];
    }
}
