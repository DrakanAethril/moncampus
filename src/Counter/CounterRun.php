<?php

declare(strict_types=1);

namespace App\Counter;

/**
 * What one recomputation found: how many rows it checked, and the ones that had drifted.
 */
final readonly class CounterRun
{
    /**
     * @param list<CounterDrift> $drifts
     */
    public function __construct(
        public string $counter,
        public int $checked,
        public array $drifts,
        public bool $dryRun,
    ) {
    }
}
