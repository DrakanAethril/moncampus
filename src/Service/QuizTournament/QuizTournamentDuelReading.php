<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * What the end-of-duel screens read off a finished duel (design/validated/quiz-tournament.md §5.3,
 * §5.4 and §10), as pure functions:
 *
 * - the score: the sum of each question's points (0 to 1, an unanswered question 0);
 * - the defeat's consolation (H-defaite): against a higher-rated opponent, what the defeat cost and
 *   what a win would have brought, by QuizTournamentElo on the ratings **before** the duel and the
 *   duels played before it - the K of that moment;
 * - the errors by theme: a question not fully earned is an error, its theme the quiz it came from;
 * - the rating line of « Mes duels »: 1000 at the start, then the rating after each duel;
 * - a question's explanation as the correction lays it out: its paragraphs, then the one that opens
 *   on « Exemple : » in its own box and the one that opens on « Source : » as a chip - the shape the
 *   question banks imported by the connector write (an explanation, an example, a source).
 */
final class QuizTournamentDuelReading
{
    /** A question earned below this is an error - everything but full marks. */
    private const float FULL = 0.999;

    /** @param list<float|null> $points per question, null when unanswered */
    public static function score(array $points): float
    {
        return round(array_sum(array_map(static fn (?float $p): float => $p ?? 0.0, $points)), 2);
    }

    public static function isError(?float $points): bool
    {
        return null === $points || $points < self::FULL;
    }

    /**
     * Only for a defeat against somebody rated higher before the duel.
     *
     * @return array{gap: int, cost: int, wouldWin: int}|null
     */
    public static function consolation(int $ratingBefore, int $ratingAfter, int $opponentBefore, int $duelsBefore): ?array
    {
        $gap = $opponentBefore - $ratingBefore;
        if ($gap <= 0) {
            return null;
        }

        return [
            'gap' => $gap,
            'cost' => $ratingBefore - $ratingAfter,
            'wouldWin' => QuizTournamentElo::stakes($ratingBefore, $opponentBefore, $duelsBefore)['win'],
        ];
    }

    /**
     * @param list<array{theme: string, points: float|null}> $questions
     *
     * @return list<array{theme: string, count: int}> most errors first, then by name
     */
    public static function errorsByTheme(array $questions): array
    {
        $counts = [];
        foreach ($questions as $question) {
            if (self::isError($question['points'])) {
                $counts[$question['theme']] = ($counts[$question['theme']] ?? 0) + 1;
            }
        }

        $themes = [];
        foreach ($counts as $theme => $count) {
            $themes[] = ['theme' => (string) $theme, 'count' => $count];
        }
        usort($themes, static fn (array $a, array $b): int => [$b['count'], $a['theme']] <=> [$a['count'], $b['theme']]);

        return $themes;
    }

    /**
     * @param list<int> $ratingsAfter the rating after each finished duel, oldest first
     *
     * @return array{points: list<int>, best: int, sinceStart: int}
     */
    public static function ratingLine(array $ratingsAfter): array
    {
        $points = [QuizTournamentElo::START, ...$ratingsAfter];

        return [
            'points' => $points,
            'best' => max($points),
            'sinceStart' => $points[\count($points) - 1] - QuizTournamentElo::START,
        ];
    }

    /** « Revanche · n restante(s) »: what the daily cap of a pair still allows today. */
    public static function challengesLeftToday(int $encountersToday): int
    {
        return max(0, QuizTournamentChallengeRules::DAILY_PAIR_CAP - $encountersToday);
    }

    /**
     * @return array{why: list<string>, example: string|null, source: string|null}
     */
    public static function explanationParts(?string $explanation): array
    {
        $parts = ['why' => [], 'example' => null, 'source' => null];
        $text = trim(str_replace("\r\n", "\n", (string) $explanation));
        if ('' === $text) {
            return $parts;
        }

        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if ('' === $paragraph) {
                continue;
            }
            if (null === $parts['example'] && 1 === preg_match('/^Exemple\s*:\s*(.+)$/su', $paragraph, $match)) {
                $parts['example'] = $match[1];
            } elseif (null === $parts['source'] && 1 === preg_match('/^Source\s*:/u', $paragraph)) {
                $parts['source'] = $paragraph;
            } else {
                $parts['why'][] = $paragraph;
            }
        }

        return $parts;
    }
}
