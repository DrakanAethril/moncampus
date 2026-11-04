<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * « Clore et apparier » (design/validated/quiz-tournament.md §8, point 3), as a pure function.
 *
 * The present players are sorted by rating and paired neighbour with neighbour. A pair that already
 * met three times today (QuizTournamentChallengeRules::DAILY_PAIR_CAP) is avoided when another
 * pairing exists: the search tries each player's nearest partners first and backtracks, within a
 * budget, and falls back to plain neighbours when no clean pairing turns up. With an odd count, one
 * player sits out: the least often exempted, then the lowest rated.
 */
final class QuizTournamentRoundPairing
{
    /** How many partial pairings the search may try before falling back on neighbours. */
    private const int SEARCH_BUDGET = 20000;

    /**
     * @param list<QuizTournamentRoundEntrant> $entrants
     * @param list<array{0: int, 1: int}>      $cappedPairs pairs of user ids at the daily cap, either order
     */
    public static function pair(array $entrants, array $cappedPairs): QuizTournamentRoundPairs
    {
        usort($entrants, static fn (QuizTournamentRoundEntrant $a, QuizTournamentRoundEntrant $b): int => [$b->rating, $a->userId] <=> [$a->rating, $b->userId]);

        $exemptId = null;
        if (1 === \count($entrants) % 2) {
            $exempt = $entrants[0];
            foreach ($entrants as $entrant) {
                if ([$entrant->exemptions, $entrant->rating, -$entrant->userId] < [$exempt->exemptions, $exempt->rating, -$exempt->userId]) {
                    $exempt = $entrant;
                }
            }
            $exemptId = $exempt->userId;
            $entrants = array_values(array_filter($entrants, static fn (QuizTournamentRoundEntrant $e): bool => $e->userId !== $exemptId));
        }

        $capped = [];
        foreach ($cappedPairs as [$a, $b]) {
            $capped[self::key($a, $b)] = true;
        }

        $ids = array_map(static fn (QuizTournamentRoundEntrant $e): int => $e->userId, $entrants);
        $budget = self::SEARCH_BUDGET;
        $pairs = self::search($ids, $capped, $budget) ?? self::neighbours($ids);

        return new QuizTournamentRoundPairs($pairs, $exemptId);
    }

    /**
     * Depth first: the highest unpaired player takes their nearest uncapped partner, and the rest is
     * paired the same way; a dead end tries the next partner.
     *
     * @param list<int>          $ids    unpaired, by rating
     * @param array<string, true> $capped
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private static function search(array $ids, array $capped, int &$budget): ?array
    {
        if ([] === $ids) {
            return [];
        }

        $first = $ids[0];
        $count = \count($ids);
        for ($i = 1; $i < $count; ++$i) {
            if (--$budget < 0) {
                return null;
            }
            if (isset($capped[self::key($first, $ids[$i])])) {
                continue;
            }

            $rest = $ids;
            unset($rest[0], $rest[$i]);
            $tail = self::search(array_values($rest), $capped, $budget);
            if (null !== $tail) {
                return [[$first, $ids[$i]], ...$tail];
            }
        }

        return null;
    }

    /**
     * @param list<int> $ids by rating
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function neighbours(array $ids): array
    {
        $pairs = [];
        for ($i = 0; $i + 1 < \count($ids); $i += 2) {
            $pairs[] = [$ids[$i], $ids[$i + 1]];
        }

        return $pairs;
    }

    private static function key(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }
}
