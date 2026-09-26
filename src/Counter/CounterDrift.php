<?php

declare(strict_types=1);

namespace App\Counter;

/**
 * One stored counter that did not hold what its source says - by how much, and on which row.
 */
final readonly class CounterDrift
{
    /**
     * @param array<string, int> $stored   column => value found
     * @param array<string, int> $expected column => value the source gives
     */
    public function __construct(
        public int $id,
        public string $label,
        public array $stored,
        public array $expected,
    ) {
    }

    /** `available_count 12 → 11, in_use_count 3 → 4` - only the columns that differ. */
    public function describe(): string
    {
        $parts = [];
        foreach ($this->expected as $column => $value) {
            $found = $this->stored[$column] ?? 0;
            if ($found !== $value) {
                $parts[] = \sprintf('%s %d → %d', $column, $found, $value);
            }
        }

        return implode(', ', $parts);
    }
}
