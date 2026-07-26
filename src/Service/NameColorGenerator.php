<?php

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
}
