<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * A transcript after one screen has been folded into it.
 *
 * Three values that always travel together, and the middle one is why this is a class rather than a
 * string: `stableLength` is the boundary between what has scrolled past - final, never rewritten -
 * and the screen as it currently is. Losing it would mean keeping a second copy of the screen
 * somewhere to compare against.
 */
final class ConsoleTranscriptState
{
    public function __construct(
        public readonly string $text,
        public readonly int $stableLength,
        public readonly bool $truncated,
    ) {
    }
}
