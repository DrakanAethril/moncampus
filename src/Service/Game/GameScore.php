<?php

declare(strict_types=1);

namespace App\Service\Game;

/**
 * One reading of App\Service\Game\GameScoreCalculator: the index, the rate of each family that
 * counted, and the weight each of them actually carried.
 *
 * $weights is not the configured barème - it is the barème **after** the families with no data have
 * left and their weight has been shared out, which is what the screens must print. Showing 30 next
 * to a family that in fact weighed 43 is how a taux stops being verifiable.
 */
final readonly class GameScore
{
    /**
     * @param int                  $index   0-100
     * @param array<string, float> $rates   family value => 0..1, only the families that counted
     * @param array<string, int>   $weights family value => applied weight, adding up to 100
     */
    public function __construct(
        public int $index,
        public array $rates,
        public array $weights,
    ) {
    }

    public function rateOf(string $family): ?float
    {
        return $this->rates[$family] ?? null;
    }

    public function weightOf(string $family): ?int
    {
        return $this->weights[$family] ?? null;
    }

    /** Whether this family had any possible at all - what the screen says instead of "0 %". */
    public function counts(string $family): bool
    {
        return isset($this->rates[$family]);
    }
}
