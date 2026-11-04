<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuizTournamentStandingBlock;

/**
 * Being ranked (design/validated/quiz-tournament.md §6.2), as pure functions.
 *
 * A player is **ranked** once they have played at least five duels, three of them in the last 14
 * days, and - the ladder rule - over those 14 days one duel against one of the five ranked players
 * nearest their rating at the time of the duel, *or* a challenge to one of them that was refused or
 * expired. The « or » is what stops the chasers from agreeing to refuse the leader until they drop
 * out; the obligation is to *play*, never to accept a given challenge.
 *
 * Four blocks, in this order:
 * - « Classement »: the ranked, rating descending (then more duels, then name), with a rank that
 *   equal ratings share;
 * - « En placement »: fewer than five duels, by duels played then rating;
 * - « Inactifs »: five duels or more, activity or ladder rule not held, by rating;
 * - « Pas encore joué »: eligible students without a duel, by name.
 *
 * A player who lost their eligibility keeps their line; a non-eligible person with no duel has none.
 * At the tournament's end the standings are computed with the same rules, at that date.
 */
final class QuizTournamentStanding
{
    public const int PLACEMENT_DUELS = 5;
    public const int ACTIVITY_DUELS = 3;
    public const int ACTIVITY_DAYS = 14;
    public const int LADDER_NEIGHBOURS = 5;

    /** @param list<QuizTournamentStandingEntry> $entries */
    public static function compute(array $entries): QuizTournamentStandings
    {
        $blocks = [];
        foreach (QuizTournamentStandingBlock::cases() as $block) {
            $blocks[$block->value] = [];
        }

        foreach ($entries as $entry) {
            if (0 === $entry->duels && !$entry->eligible) {
                continue;
            }

            $block = self::blockOf($entry);
            $blocks[$block->value][] = $entry;
        }

        $collator = new \Collator('fr_FR');
        $byName = static fn (QuizTournamentStandingEntry $a, QuizTournamentStandingEntry $b): int => (int) $collator->compare($a->sortName, $b->sortName) ?: $a->userId <=> $b->userId;

        usort($blocks[QuizTournamentStandingBlock::Ranked->value], static fn (QuizTournamentStandingEntry $a, QuizTournamentStandingEntry $b): int => [$b->rating, $b->duels] <=> [$a->rating, $a->duels] ?: $byName($a, $b));
        usort($blocks[QuizTournamentStandingBlock::Placement->value], static fn (QuizTournamentStandingEntry $a, QuizTournamentStandingEntry $b): int => [$b->duels, $b->rating] <=> [$a->duels, $a->rating] ?: $byName($a, $b));
        usort($blocks[QuizTournamentStandingBlock::Inactive->value], static fn (QuizTournamentStandingEntry $a, QuizTournamentStandingEntry $b): int => $b->rating <=> $a->rating ?: $byName($a, $b));
        usort($blocks[QuizTournamentStandingBlock::NotPlayed->value], $byName);

        $rows = [];
        foreach ($blocks as $block => $blockEntries) {
            $rows[$block] = [];
            $rank = 0;
            $previousRating = null;
            foreach ($blockEntries as $index => $entry) {
                $ranked = QuizTournamentStandingBlock::Ranked->value === $block;
                if ($ranked && $entry->rating !== $previousRating) {
                    $rank = $index + 1;
                    $previousRating = $entry->rating;
                }

                $rows[$block][] = new QuizTournamentStandingRow(
                    $entry,
                    QuizTournamentStandingBlock::from($block),
                    $ranked ? $rank : null,
                    max(0, self::ACTIVITY_DUELS - $entry->recentDuels),
                    !$entry->ladderMet,
                );
            }
        }

        return new QuizTournamentStandings($rows);
    }

    public static function blockOf(QuizTournamentStandingEntry $entry): QuizTournamentStandingBlock
    {
        if (0 === $entry->duels) {
            return QuizTournamentStandingBlock::NotPlayed;
        }

        if ($entry->duels < self::PLACEMENT_DUELS) {
            return QuizTournamentStandingBlock::Placement;
        }

        return $entry->recentDuels >= self::ACTIVITY_DUELS && $entry->ladderMet
            ? QuizTournamentStandingBlock::Ranked
            : QuizTournamentStandingBlock::Inactive;
    }

    /**
     * The ranked players nearest this rating, nearest first - equal distances putting the higher
     * rating first, the rule being about climbing. The player is never their own neighbour.
     *
     * @param list<array{id: int, rating: int}> $ranked
     *
     * @return list<int>
     */
    public static function nearestRanked(int $playerId, int $rating, array $ranked, int $count = self::LADDER_NEIGHBOURS): array
    {
        $others = array_values(array_filter($ranked, static fn (array $row): bool => $row['id'] !== $playerId));
        usort($others, static fn (array $a, array $b): int => [abs($a['rating'] - $rating), $b['rating'], $a['id']] <=> [abs($b['rating'] - $rating), $a['rating'], $b['id']]);

        return array_map(static fn (array $row): int => $row['id'], \array_slice($others, 0, $count));
    }

    /**
     * Does a duel against (or a challenge to) this opponent count for the ladder rule, on the
     * standings of the moment? Stamped on the duel and on the challenge when they happen.
     *
     * While nobody else is ranked the rule holds by itself: the first player out of placement would
     * otherwise wait for a ranked neighbour that cannot exist yet.
     *
     * @param list<array{id: int, rating: int}> $ranked
     */
    public static function isLadderOpponent(int $playerId, int $rating, int $opponentId, array $ranked): bool
    {
        $nearest = self::nearestRanked($playerId, $rating, $ranked);

        return [] === $nearest || \in_array($opponentId, $nearest, true);
    }
}
