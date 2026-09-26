<?php

declare(strict_types=1);

namespace App\Service;

/**
 * « Part du quiz » on the launch screen (1c): when several quizzes are merged into one pool, the
 * teacher may say how much of the final draw each of them provides, as a percentage of the number
 * of questions drawn. Optional quiz by quiz - a quiz given no share draws from what the others left.
 *
 * Pure arithmetic on primitives, shared by the launch (which checks and freezes the quotas on the
 * instance) and nothing else: the draw reads the frozen counts rather than recomputing them, for
 * the same reason the difficulty counts are frozen (App\Entity\QuizInstance).
 *
 * The rules, and why they refuse rather than bend:
 * - shares add up to at most 100 %;
 * - when every quiz has one, they add up to exactly 100 % - nobody is left to take the rest, and a
 *   quiz drawing more than it was given would contradict what the teacher typed;
 * - a quiz cannot be asked for more questions than it holds, and the quizzes without a share must
 *   hold what is left. Filling the gap from another quiz would quietly break the split the teacher
 *   asked for, which reads as a bug.
 */
final class QuizPoolShares
{
    /**
     * The number of questions reserved for each quiz of the pool, in pool order.
     *
     * The shared quizzes together take their total rounded to the nearest question (all of the draw
     * when their shares cover everything), and that total is split by largest remainder, ties going
     * to the earlier quiz - so the quotas always add up exactly.
     *
     * @param list<int|null> $shares the percentage given to each quiz, null where none was
     *
     * @return list<int|null> questions reserved per quiz, null where the quiz has no share
     */
    public function quotas(array $shares, int $questionCount): array
    {
        $given = array_filter($shares, static fn (?int $share): bool => null !== $share);
        // A share is a split between quizzes: alone in the pool, a quiz simply is the draw.
        if (\count($shares) < 2 || [] === $given) {
            return array_fill(0, \count($shares), null);
        }

        $questionCount = max(0, $questionCount);
        $sum = array_sum($given);
        $target = \count($given) === \count($shares) || $sum >= 100
            ? $questionCount
            : intdiv($sum * $questionCount + 50, 100);

        $quotas = [];
        $remainders = [];
        foreach ($shares as $index => $share) {
            if (null === $share) {
                $quotas[$index] = null;
                continue;
            }
            $quotas[$index] = intdiv($share * $questionCount, 100);
            $remainders[$index] = ($share * $questionCount) % 100;
        }

        $missing = $target - array_sum(array_map('intval', $quotas));
        // Stable sort on the remainder alone: equal remainders keep pool order.
        uksort($remainders, static fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_keys($remainders) as $index) {
            if ($missing <= 0) {
                break;
            }
            $quotas[$index] = (int) $quotas[$index] + 1;
            --$missing;
        }

        return $quotas;
    }

    /**
     * The first rule these shares break, or null when they can be drawn as asked.
     *
     * @param list<int|null> $shares    the percentage given to each quiz, null where none was
     * @param list<int>      $available the questions each quiz holds, in the same order
     *
     * @return array{key: string, index?: int, params: array<string, int>}|null `index` names the quiz at fault when there is one
     */
    public function violation(array $shares, array $available, int $questionCount): ?array
    {
        $given = array_filter($shares, static fn (?int $share): bool => null !== $share);
        if (\count($shares) < 2 || [] === $given) {
            return null;
        }

        $sum = array_sum($given);
        if ($sum > 100) {
            return ['key' => 'quizLaunchShareOverflowError', 'params' => ['%sum%' => $sum]];
        }
        if (\count($given) === \count($shares) && $sum < 100) {
            return ['key' => 'quizLaunchShareIncompleteError', 'params' => ['%sum%' => $sum]];
        }

        $quotas = $this->quotas($shares, $questionCount);
        $rest = max(0, $questionCount);
        $restAvailable = 0;
        foreach ($quotas as $index => $quota) {
            $holds = $available[$index] ?? 0;
            if (null === $quota) {
                $restAvailable += $holds;
                continue;
            }
            if ($quota > $holds) {
                return ['key' => 'quizLaunchShareTooLargeError', 'index' => $index, 'params' => ['%quota%' => $quota, '%available%' => $holds, '%share%' => (int) $shares[$index]]];
            }
            $rest -= $quota;
        }

        if ($rest > $restAvailable) {
            return ['key' => 'quizLaunchShareRestTooLargeError', 'params' => ['%rest%' => $rest, '%available%' => $restAvailable]];
        }

        return null;
    }
}
