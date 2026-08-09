<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Renders a minute count the way a teacher reads a séance duration: "55 min", "1 h", "1 h 30".
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
}
