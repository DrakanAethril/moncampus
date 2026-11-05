<?php

declare(strict_types=1);

namespace App\Service\Kahoot;

/**
 * The margin of a Kahoot « Slider », read back from the game it was played in.
 *
 * A report gives the expected value and the range, never the margin the author chose - but it does
 * give, for every player, the value they set and the points it earned. Kahoot only pays an answer
 * inside the margin, so the margin is at least as wide as the farthest answer that scored, and
 * narrower than the nearest one that did not. Within that bracket the round number a person would
 * have picked is taken (1, 2, 2.5, 3, 4, 5, 6 or 8 times a power of ten), else the farthest scored distance
 * itself.
 *
 * A guess, and said so: the import names it next to the question (App\Service\Kahoot\KahootReport).
 * When nobody scored but the exact value, the margin was none; when nobody answered at all there is
 * nothing to read, and the question asks for the exact value until the teacher widens it.
 */
final class KahootSliderTolerance
{
    /**
     * @param float                                     $expected the slider's right value
     * @param list<array{value: float, scored: bool}> $answers  every answer a player actually set
     *
     * @return array{tolerance: float, guessed: bool} guessed is false when the game said nothing
     */
    public static function infer(float $expected, array $answers): array
    {
        $scored = null;
        $missed = null;
        foreach ($answers as $answer) {
            $distance = abs($answer['value'] - $expected);
            if ($answer['scored']) {
                $scored = max($scored ?? 0.0, $distance);
            } else {
                $missed = min($missed ?? \INF, $distance);
            }
        }

        if (null === $scored) {
            // Nobody scored: either nobody answered (nothing to read) or everybody missed, which only
            // says the margin is narrower than the nearest miss - the exact value is the safe reading.
            return ['tolerance' => 0.0, 'guessed' => null !== $missed];
        }

        if (0.0 === $scored) {
            return ['tolerance' => 0.0, 'guessed' => true];
        }

        $round = self::roundAtLeast($scored);

        return ['tolerance' => null === $missed || $round < $missed ? $round : $scored, 'guessed' => true];
    }

    /** The smallest of 1, 2, 2.5, 3, 4, 5, 6 and 8 times a power of ten that is not below $value. */
    private static function roundAtLeast(float $value): float
    {
        $power = 10 ** floor(log10($value));
        foreach ([1.0, 2.0, 2.5, 3.0, 4.0, 5.0, 6.0, 8.0, 10.0] as $factor) {
            $candidate = $factor * $power;
            // A hair of slack: 10 ** floor(log10(300)) * 3 is not always exactly 300 in binary.
            if ($candidate >= $value - 1e-9 * $value) {
                return round($candidate, 10);
            }
        }

        return $value;
    }
}
