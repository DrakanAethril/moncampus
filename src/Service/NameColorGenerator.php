<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deterministic color from an arbitrary string (e.g. a Program's short name) - the same input
 * always maps to the same HSL color, so nothing needs to persist a color choice anywhere. Used to
 * give each formation a stable, distinct color on the teacher's personal timetable (see
 * LessonSessionEventFormatter and TeacherTimetableController), unlike per-Program calendars,
 * which color by Option instead (an admin-picked hex, not generated).
 */
class NameColorGenerator
{
    // Fixed saturation/lightness keep every generated color similarly vivid regardless of hue,
    // only the hue itself (0-359) varies by input - crc32 gives a wide, well-distributed spread
    // across the hue wheel for arbitrary strings without needing a cryptographic hash.
    public function generate(string $name): string
    {
        $hue = crc32($name) % 360;

        return sprintf('hsl(%d, 65%%, 45%%)', $hue);
    }

    // Same color as generate(), as a hex string - for consumers that need a value an
    // <input type="color"> or a stored Cohort::$color can hold (those can't carry hsl()).
    public function generateHex(string $name): string
    {
        $hue = crc32($name) % 360;

        return sprintf('#%02x%02x%02x', ...$this->hslToRgb($hue, 0.65, 0.45));
    }

    /** @return array{int, int, int} */
    private function hslToRgb(int $hue, float $saturation, float $lightness): array
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $secondary = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - $chroma / 2;

        [$r, $g, $b] = match (intdiv($hue, 60)) {
            0 => [$chroma, $secondary, 0],
            1 => [$secondary, $chroma, 0],
            2 => [0, $chroma, $secondary],
            3 => [0, $secondary, $chroma],
            4 => [$secondary, 0, $chroma],
            default => [$chroma, 0, $secondary],
        };

        return [(int) round(($r + $match) * 255), (int) round(($g + $match) * 255), (int) round(($b + $match) * 255)];
    }
}
