<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How often something happens, in the form a reader would use out loud.
 *
 * Above one a day, "40 commits per day" is the natural sentence and "1 commit every 0.02 days" is
 * nonsense; below one a day it is exactly the other way round. This class only decides which of the
 * two sentences applies and rounds the number to something a person can hold - the wording itself
 * lives in the translations.
 */
class Cadence
{
    /**
     * @return array{perDay: bool, rate: float}|null null when there is nothing to divide
     */
    public function describe(int $count, int $days): ?array
    {
        if ($count <= 0 || $days <= 0) {
            return null;
        }

        $perDay = $count / $days;

        return $perDay >= 1.0
            ? ['perDay' => true, 'rate' => $this->round($perDay)]
            : ['perDay' => false, 'rate' => $this->round($days / $count)];
    }

    /** A tenth is meaningful at 1.1 a day and noise at 40 - the decimal goes above ten. */
    private function round(float $value): float
    {
        return $value >= 10.0 ? round($value) : round($value, 1);
    }
}
