<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;

/**
 * The validation radius a flag should have, from the vegetation around it.
 *
 * A phone's fix is worth a few metres in the open and degrades under a canopy: the signal is
 * reflected and weakened by the leaves, and the error roughly doubles under a closed forest. A
 * tolerance set for a meadow then refuses runners who are standing at the flag. The thresholds
 * read the LiDAR HD vegetation height (App\Entity\EcoCheckpoint::$canopyHeight):
 *
 * - under 3 m - grass, crops, low scrub: 20 m;
 * - 3 to 12 m - hedgerows, young or open woodland: 25 m;
 * - 12 m and more - a closed canopy: 30 m.
 *
 * It only ever advises *more* than the flag already has. A teacher who widened a radius on purpose
 * is not told to narrow it, and a radius already generous enough is not mentioned at all - which,
 * since the default went up to 60 m, means the advice now only speaks to a flag narrowed by hand.
 */
final class EcoToleranceAdvisor
{
    // What open ground calls for. It was the default radius until that went up to 60 m; it stays the
    // floor of the scale, so a flag over a meadow is never advised more than a flag under trees.
    public const int OPEN_GROUND_METERS = 20;

    /** The radius the canopy calls for, or null when there is no reading to go on. */
    public function recommended(?float $canopyHeight): ?int
    {
        return match (true) {
            null === $canopyHeight => null,
            $canopyHeight >= 12.0 => 30,
            $canopyHeight >= 3.0 => 25,
            default => self::OPEN_GROUND_METERS,
        };
    }

    /** The advice for this flag, or null when its current tolerance is already enough. */
    public function adviceFor(EcoCheckpoint $checkpoint): ?int
    {
        $recommended = $this->recommended($checkpoint->getCanopyHeight());

        return null !== $recommended && $recommended > $checkpoint->getToleranceMeters() ? $recommended : null;
    }
}
