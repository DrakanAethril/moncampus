<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The range a numérique is answered within when it is shown as a slider - Kahoot's « Slider »,
 * which the live concours draws as one (min, max and the step the thumb moves by).
 *
 * Optional, and the same normalisation whoever writes it - the editor, the JSON import, the Kahoot
 * import - so that a stored range is always usable: min below max, a positive step no wider than
 * the range. Anything else is no range at all, and the question is then answered by typing.
 */
final class NumericRange
{
    /**
     * @return array{min: float, max: float, step: float}|null
     */
    public static function normalize(mixed $min, mixed $max, mixed $step = null): ?array
    {
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }

        $low = (float) $min;
        $high = (float) $max;
        if (!is_finite($low) || !is_finite($high) || $low >= $high) {
            return null;
        }

        $stride = is_numeric($step) ? abs((float) $step) : 0.0;
        if ($stride <= 0.0 || $stride > $high - $low) {
            $stride = 1.0 <= $high - $low ? 1.0 : ($high - $low) / 100;
        }

        return ['min' => $low, 'max' => $high, 'step' => $stride];
    }
}
