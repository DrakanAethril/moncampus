<?php

declare(strict_types=1);

namespace App\Util;

/**
 * A hand-written duration read as a number of minutes, as a string ready for the DECIMAL(10,2)
 * columns that hold one (SeanceTemplate::$duree, SeancePhaseTemplate::$duree).
 *
 * Extracted from App\Command\ImportNotionSequencesCommand, where it was a private method: the
 * sequence import assistant needs the same reading, and a second copy of this regular expression is
 * exactly the kind of thing that drifts. Static because it holds nothing - it is a reading of a
 * string, not a service with collaborators.
 *
 * A bare number is deliberately unreadable. The column is minutes, so "1.5" written for "1 h 30"
 * would be stored and displayed as "2 min": plausible, wrong, and silent. Refusing it is what lets
 * a caller say so.
 */
final class DurationParser
{
    /**
     * Durations written by hand, and therefore of every shape: "55 minutes", "55’", "1h20 1/2G",
     * "4H", "20-25 minutes", "2h + 2h". The first duration read wins - the low bound of a range,
     * the duration of one group when the séance is played twice - except when they explicitly add
     * up.
     */
    public static function minutes(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ('' === $raw || !preg_match_all('/(\d+)\s*[hH](?:\s*(\d{1,2}))?|(\d+)\s*(?:-\s*\d+\s*)?(?:minutes?|min\b|’|\')/u', $raw, $matches, \PREG_SET_ORDER)) {
            return null;
        }

        $values = [];
        foreach ($matches as $match) {
            $values[] = '' !== ($match[1] ?? '')
                ? 60 * (int) $match[1] + (int) ($match[2] ?? 0)
                : (int) ($match[3] ?? 0);
        }

        $minutes = str_contains($raw, '+') ? array_sum($values) : $values[0];

        return $minutes > 0 ? (string) $minutes : null;
    }
}
