<?php

declare(strict_types=1);

namespace App\SeriousGame\Stats;

/**
 * The arithmetic of the serious games' statistics (design/validated/serious-games-neko-con.md §6),
 * as pure functions on timestamps and primitives:
 *
 * - **the time played** is the sum of the gaps between a player's successive moves, leaving out the
 *   pauses of more than ten minutes - a tab left open is not play;
 * - **school time** is Monday to Friday, 8:00 to 18:00, Paris time; a gap counts where it starts;
 * - weeks are ISO weeks, days and hours are read in Paris.
 */
final class PlayStats
{
    /** A gap longer than this between two moves is a pause, not play. */
    public const int PAUSE_SECONDS = 600;

    private const string ZONE = 'Europe/Paris';

    /** @param list<int> $moments */
    public static function playSeconds(array $moments): int
    {
        return array_sum(self::schoolSplit($moments));
    }

    /**
     * The time played, split by where each gap starts: on school time or outside.
     *
     * @param list<int> $moments
     *
     * @return array{school: int, outside: int}
     */
    public static function schoolSplit(array $moments): array
    {
        sort($moments);
        $split = ['school' => 0, 'outside' => 0];
        for ($i = 1, $n = \count($moments); $i < $n; ++$i) {
            $gap = $moments[$i] - $moments[$i - 1];
            if ($gap <= 0 || $gap > self::PAUSE_SECONDS) {
                continue;
            }
            $split[self::isSchoolTime($moments[$i - 1]) ? 'school' : 'outside'] += $gap;
        }

        return $split;
    }

    public static function isSchoolTime(int $moment): bool
    {
        $local = self::local($moment);
        $weekday = (int) $local->format('N');
        $minutes = 60 * (int) $local->format('G') + (int) $local->format('i');

        return $weekday <= 5 && $minutes >= 8 * 60 && $minutes < 18 * 60;
    }

    public static function week(int $moment): string
    {
        return self::local($moment)->format('o-\WW');
    }

    public static function day(int $moment): string
    {
        return self::local($moment)->format('Y-m-d');
    }

    /** @param list<int|float> $values */
    public static function median(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $n = \count($values);
        $middle = intdiv($n, 2);

        return 0 === $n % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : (float) $values[$middle];
    }

    /**
     * How many students chose each option first - the reading of a question's options: a wrong one
     * nobody takes serves nothing, a wrong one taken more than the right one says the question or
     * the notion is not clear.
     *
     * @param list<array{player: int, input: ?string}> $answers oldest first
     *
     * @return list<int> option rank => students
     */
    public static function firstChoices(array $answers, int $options): array
    {
        $counts = array_fill(0, max(0, $options), 0);
        $seen = [];
        foreach ($answers as $answer) {
            if (isset($seen[$answer['player']]) || null === $answer['input'] || !ctype_digit($answer['input'])) {
                continue;
            }
            $seen[$answer['player']] = true;
            $rank = (int) $answer['input'];
            if ($rank < $options) {
                ++$counts[$rank];
            }
        }

        return $counts;
    }

    /**
     * The criteria refused deliveries failed, the most frequent first.
     *
     * @param list<string|null> $inputs the stored input of each refused delivery
     *
     * @return array<string, int>
     */
    public static function criteria(array $inputs): array
    {
        $counts = [];
        foreach ($inputs as $input) {
            foreach (explode(' · ', (string) $input) as $criterion) {
                $criterion = trim($criterion);
                if ('' !== $criterion) {
                    $counts[$criterion] = ($counts[$criterion] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return $counts;
    }

    private static function local(int $moment): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$moment))->setTimezone(new \DateTimeZone(self::ZONE));
    }
}
