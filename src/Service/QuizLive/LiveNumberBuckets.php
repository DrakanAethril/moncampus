<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

/**
 * What the board shows after a numérique: the room's answers grouped into bars, the bar holding the
 * expected value marked right. A few distinct answers are shown one by one; more are grouped into
 * equal bands over the slider's range (or over what was answered, around the expected value).
 */
final class LiveNumberBuckets
{
    public const int MAX_BARS = 8;

    /**
     * @param list<float>                                  $values   every answer given
     * @param array{min: float, max: float, step: float}|null $range
     *
     * @return list<array{from: float, to: float, count: int, correct: bool}>
     */
    public static function of(array $values, float $expected, float $margin, ?array $range): array
    {
        if ([] === $values) {
            return [];
        }

        $distinct = array_values(array_unique(array_map(static fn (float $value): string => (string) $value, $values)));
        if (\count($distinct) <= self::MAX_BARS) {
            $bars = [];
            foreach ($distinct as $raw) {
                $value = (float) $raw;
                $bars[] = [
                    'from' => $value,
                    'to' => $value,
                    'count' => \count(array_filter($values, static fn (float $one): bool => $one === $value)),
                    'correct' => abs($value - $expected) <= $margin + 1e-9 * max(1.0, abs($expected)),
                ];
            }
            usort($bars, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

            return $bars;
        }

        $low = min($range['min'] ?? min($values), min($values), $expected);
        $high = max($range['max'] ?? max($values), max($values), $expected);
        $width = ($high - $low) / self::MAX_BARS;

        $bars = [];
        for ($i = 0; $i < self::MAX_BARS; ++$i) {
            $from = $low + $i * $width;
            $to = self::MAX_BARS - 1 === $i ? $high : $from + $width;
            $bars[] = ['from' => $from, 'to' => $to, 'count' => 0, 'correct' => $expected >= $from && ($expected < $to || self::MAX_BARS - 1 === $i)];
        }
        foreach ($values as $value) {
            $index = 0.0 === $width ? 0 : min(self::MAX_BARS - 1, (int) floor(($value - $low) / $width));
            ++$bars[$index]['count'];
        }

        return $bars;
    }
}
