<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

use App\Enum\QuestionType;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The questions of a duel (design/validated/quiz-tournament.md §5.1), as pure, seeded functions.
 *
 * - Only the formats that play well on a phone in 30 to 40 seconds are admitted - QCM, QCM à
 *   réponses multiples, vrai/faux, numérique (QuestionType::isPlayableInTournament()), the same idea
 *   as the live contest's QuestionType::isAvailableInLiveContest(). Nobody picks the types.
 * - The duel is shared between the source quizzes in proportion to their admitted size (largest
 *   remainders), then shuffled.
 * - At most N vrai/faux, a hard cap.
 * - The questions either player saw in their last three duels are left out first, as long as the
 *   reserve allows - they come back rather than fail the draw.
 * - It never fails: a reserve too small for a full duel (quizzes edited after the tournament was
 *   checked) gives a shorter one.
 *
 * Same seed, same duel: both players get the same questions, and so do the choices
 * (choiceOrder()).
 */
final class QuizTournamentQuestionDraw
{
    public static function admits(QuestionType $type): bool
    {
        return $type->isPlayableInTournament();
    }

    /**
     * @param list<QuizTournamentDrawCandidate> $pool         the admitted reserve
     * @param list<int>                         $recentlySeen question ids either player saw in their last three duels
     *
     * @return list<int> the drawn question ids, in play order
     */
    public static function draw(array $pool, array $recentlySeen, int $count, int $maxTrueFalse, int $seed): array
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $seen = array_fill_keys($recentlySeen, true);

        // One ordered list per source: shuffled, then the unseen questions first.
        $bySource = [];
        foreach ($pool as $candidate) {
            $bySource[$candidate->sourceId][] = $candidate;
        }
        ksort($bySource);
        foreach ($bySource as $sourceId => $candidates) {
            /** @var list<QuizTournamentDrawCandidate> $shuffled */
            $shuffled = $randomizer->shuffleArray($candidates);
            $bySource[$sourceId] = [
                ...array_filter($shuffled, static fn (QuizTournamentDrawCandidate $c): bool => !isset($seen[$c->questionId])),
                ...array_filter($shuffled, static fn (QuizTournamentDrawCandidate $c): bool => isset($seen[$c->questionId])),
            ];
        }

        $target = min($count, \count($pool));
        $quotas = self::quotas(array_map('count', $bySource), $target);

        $picked = [];
        $leftovers = [];
        $trueFalse = 0;
        $take = static function (QuizTournamentDrawCandidate $candidate) use (&$picked, &$trueFalse, $maxTrueFalse): bool {
            if ($candidate->trueFalse) {
                if ($trueFalse >= $maxTrueFalse) {
                    return false;
                }
                ++$trueFalse;
            }
            $picked[] = $candidate;

            return true;
        };

        // Each source its share.
        foreach ($bySource as $sourceId => $candidates) {
            $taken = 0;
            foreach ($candidates as $candidate) {
                if ($taken < $quotas[$sourceId] && $take($candidate)) {
                    ++$taken;
                } else {
                    $leftovers[] = $candidate;
                }
            }
        }

        // A share a source could not fill (its vrai/faux over the cap) goes to the others, unseen
        // questions first.
        if (\count($picked) < $target) {
            $unseen = array_filter($leftovers, static fn (QuizTournamentDrawCandidate $c): bool => !isset($seen[$c->questionId]));
            $seenAgain = array_filter($leftovers, static fn (QuizTournamentDrawCandidate $c): bool => isset($seen[$c->questionId]));
            foreach ([...$randomizer->shuffleArray($unseen), ...$randomizer->shuffleArray($seenAgain)] as $candidate) {
                if (\count($picked) >= $target) {
                    break;
                }
                /* @var QuizTournamentDrawCandidate $candidate */
                $take($candidate);
            }
        }

        /** @var list<QuizTournamentDrawCandidate> $order */
        $order = $randomizer->shuffleArray($picked);

        return array_map(static fn (QuizTournamentDrawCandidate $c): int => $c->questionId, $order);
    }

    /**
     * The order a question's choices are shown in - the same for both players. A vrai/faux keeps
     * « Vrai » then « Faux », a numeric question has no choices to move.
     *
     * @param list<int> $choiceIds in the question's own order
     *
     * @return list<int>
     */
    public static function choiceOrder(QuestionType $type, array $choiceIds, int $seed, int $rank): array
    {
        if (!\in_array($type, [QuestionType::Qcm, QuestionType::QcmMulti], true)) {
            return $choiceIds;
        }

        /** @var list<int> $shuffled */
        $shuffled = (new Randomizer(new Mt19937(crc32($seed.':'.$rank))))->shuffleArray($choiceIds);

        return $shuffled;
    }

    /**
     * The save-time check of §3: the reserve must allow a full duel - at least $count admitted
     * questions, of which at least $count − $maxTrueFalse are not vrai/faux. Zeros mean it does.
     *
     * @return array{missing: int, missingNotTrueFalse: int}
     */
    public static function reserveShortfall(int $admitted, int $admittedNotTrueFalse, int $count, int $maxTrueFalse): array
    {
        return [
            'missing' => max(0, $count - $admitted),
            'missingNotTrueFalse' => max(0, $count - $maxTrueFalse - $admittedNotTrueFalse),
        ];
    }

    /**
     * Largest-remainder shares of $target between sources of these sizes, none above its size.
     *
     * @param array<int, int> $sizes source id => size
     *
     * @return array<int, int> source id => share
     */
    private static function quotas(array $sizes, int $target): array
    {
        $total = array_sum($sizes);
        if (0 === $total || $target <= 0) {
            return array_map(static fn (): int => 0, $sizes);
        }

        $quotas = [];
        $remainders = [];
        foreach ($sizes as $sourceId => $size) {
            $exact = $target * $size / $total;
            $quotas[$sourceId] = min($size, (int) floor($exact));
            $remainders[$sourceId] = $exact - floor($exact);
        }

        // Largest remainders first, the bigger source on a tie, then the lower id.
        $order = array_keys($sizes);
        usort($order, static fn (int $a, int $b): int => [$remainders[$b], $sizes[$b], $a] <=> [$remainders[$a], $sizes[$a], $b]);

        $left = $target - array_sum($quotas);
        while ($left > 0) {
            $given = false;
            foreach ($order as $sourceId) {
                if ($left > 0 && $quotas[$sourceId] < $sizes[$sourceId]) {
                    ++$quotas[$sourceId];
                    --$left;
                    $given = true;
                }
            }
            if (!$given) {
                break;
            }
        }

        return $quotas;
    }
}
