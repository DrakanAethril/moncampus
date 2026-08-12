<?php

declare(strict_types=1);

namespace App\Util;

/**
 * A position inside a video, read from and written for a human - the CSV column a teacher fills in a
 * spreadsheet, the editor's own field, and the labels drawn along the timeline.
 *
 * Three spellings are read because all three are what people write: "5:40", "1:05:40" and a bare
 * number of seconds. Anything else is refused rather than guessed at: a timecode read wrong moves a
 * question to another part of the lecture, which is worse than a line the importer says it could not
 * read (see App\Service\QuizCsvImporter, where a bad timecode rejects its row and no other).
 */
final class Timecode
{
    /** @return int|null seconds, or null when the value is not a timecode at all */
    public static function parse(string $raw): ?int
    {
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $parts = explode(':', $value);
        if (\count($parts) < 2 || \count($parts) > 3) {
            return null;
        }

        foreach ($parts as $part) {
            if (!ctype_digit($part)) {
                return null;
            }
        }

        // Every part but the first counts in sixties: "5:75" is either 5 min 75 s or a typo, and
        // silently reading it as 6:15 moves the question a minute later in the lecture.
        foreach (\array_slice($parts, 1) as $part) {
            if ((int) $part > 59) {
                return null;
            }
        }

        $seconds = 0;
        foreach ($parts as $part) {
            $seconds = $seconds * 60 + (int) $part;
        }

        return $seconds;
    }

    /** "5:40", and "1:05:40" only once there is an hour to show. */
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? \sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : \sprintf('%d:%02d', $minutes, $rest);
    }
}
