<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Renders a minute count the way a teacher reads a séance duration: "55 min", "1 h", "1 h 30",
 * and a millisecond count the way one reads a time spent on a quiz question: "4 s", "1 min 02".
 *
 * Static rather than a service because both a Twig filter (App\Twig\DurationExtension) and the
 * JSON endpoints that feed the progression's JavaScript need the exact same string - formatting a
 * duration twice, once per language, is how "55 min" ends up displayed as "55 h" on one screen.
 */
final class DurationFormatter
{
    public static function minutes(?int $minutes): string
    {
        $minutes = max(0, (int) $minutes);

        if ($minutes < 60) {
            return sprintf('%d min', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 0 === $rest ? sprintf('%d h', $hours) : sprintf('%d h %02d', $hours, $rest);
    }

    /**
     * A measured duration in milliseconds, read as a stopwatch: "4 s" under a minute, "1 min 02"
     * above. Null - a question never served, or never answered - prints an em dash rather than
     * "0 s", which would claim a measurement that was never made.
     */
    public static function milliseconds(?int $milliseconds): string
    {
        if (null === $milliseconds) {
            return '—';
        }

        $seconds = (int) round(max(0, $milliseconds) / 1000);

        if ($seconds < 60) {
            return sprintf('%d s', $seconds);
        }

        return sprintf('%d min %02d', intdiv($seconds, 60), $seconds % 60);
    }
}
