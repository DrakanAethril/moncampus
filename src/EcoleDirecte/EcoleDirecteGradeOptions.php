<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

/**
 * How one evaluation is written into École Directe, as the teacher chose it on the send screen:
 * out of its own scale or brought back to 20, and with which coefficient.
 *
 * **Brought back to 20** multiplies every number by 20 / scale and rounds it to the hundredth - a
 * grade in brackets stays in brackets, `abs` and `ne` carry no number and do not move. The École
 * Directe evaluation is then out of 20, which is what an existing one is compared against.
 *
 * **The coefficient is the one École Directe's evaluation is created with.** An evaluation already
 * there keeps its own: nothing on this side changes it, the preview only says when the two differ.
 */
final readonly class EcoleDirecteGradeOptions
{
    public const float TARGET_SCALE = 20.0;
    public const float MIN_COEFFICIENT = 0.01;
    public const float MAX_COEFFICIENT = 100.0;

    public function __construct(
        public bool $outOf20,
        public float $coefficient,
    ) {
    }

    /** Null when the coefficient is not one École Directe could be sent. */
    public static function of(bool $outOf20, ?float $coefficient): ?self
    {
        if (null === $coefficient || !is_finite($coefficient) || $coefficient < self::MIN_COEFFICIENT || $coefficient > self::MAX_COEFFICIENT) {
            return null;
        }

        return new self($outOf20, round($coefficient, 2));
    }

    /** The scale École Directe's evaluation is out of. */
    public function scale(float $evaluationScale): float
    {
        return $this->outOf20 ? self::TARGET_SCALE : $evaluationScale;
    }

    /** A MonCampus value, out of the evaluation's scale, as it is sent. */
    public function value(float $value, float $evaluationScale): float
    {
        if (!$this->outOf20 || $evaluationScale <= 0.0 || abs($evaluationScale - self::TARGET_SCALE) < 0.001) {
            return $value;
        }

        return round($value * self::TARGET_SCALE / $evaluationScale, 2);
    }
}
