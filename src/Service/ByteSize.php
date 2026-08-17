<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads and writes the sizes a human types and a screen shows - "1 Go", "2500 Mo", "2G", 312 Mo.
 *
 * One parser rather than two: it is what reads `FILE_LIBRARY_DEFAULT_QUOTA` from the environment
 * **and** what the admin's quota field accepts (design/validated/file-library.md, "The admin quota
 * field"). A deployment that wants 2 Go should not need a code change, and an admin typing "2 Go"
 * should not need to know how many bytes that is.
 *
 * Binary units throughout, and stated rather than assumed: 1 Go is 1 073 741 824 bytes, which is
 * what every file manager on the teacher's machine will also say.
 */
final class ByteSize
{
    private const int KILO = 1024;

    /** @var array<string, int> the multipliers accepted on input, French and English spellings */
    private const array UNITS = [
        'k' => self::KILO,
        'ko' => self::KILO,
        'kb' => self::KILO,
        'm' => self::KILO ** 2,
        'mo' => self::KILO ** 2,
        'mb' => self::KILO ** 2,
        'g' => self::KILO ** 3,
        'go' => self::KILO ** 3,
        'gb' => self::KILO ** 3,
        't' => self::KILO ** 4,
        'to' => self::KILO ** 4,
        'tb' => self::KILO ** 4,
    ];

    /**
     * Bytes, or null when the text says nothing usable.
     *
     * Null is a real answer and not a failure: an empty quota field means "the platform default",
     * which is the whole point of the column being nullable.
     */
    public static function parse(?string $text): ?int
    {
        if (null === $text) {
            return null;
        }

        // A French keyboard writes "1,5 Go", and a space between number and unit is the normal way
        // to type it - both are read rather than refused.
        $normalised = str_replace([' ', ' ', ','], ['', '', '.'], mb_strtolower(trim($text)));

        if ('' === $normalised) {
            return null;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)([a-z]*)$/', $normalised, $matches)) {
            return null;
        }

        $unit = $matches[2];

        if ('' !== $unit && !isset(self::UNITS[$unit])) {
            return null;
        }

        $bytes = (float) $matches[1] * ('' === $unit ? 1 : self::UNITS[$unit]);

        return $bytes >= 1 ? (int) round($bytes) : null;
    }

    /**
     * The size as a screen shows it - "312 Mo", "1 Go", "940 Ko".
     *
     * Kilobytes below a megabyte: a course video is always in the tens of megabytes, but a three-page
     * PDF rounded to "1 Mo" reads as a broken counter rather than as a small file.
     */
    public static function format(?int $bytes): string
    {
        if (null === $bytes || $bytes <= 0) {
            return '0 Ko';
        }

        if ($bytes < self::KILO ** 2) {
            return \sprintf('%d Ko', max(1, (int) round($bytes / self::KILO)));
        }

        if ($bytes < self::KILO ** 3) {
            $megabytes = $bytes / self::KILO ** 2;

            return \sprintf('%s Mo', $megabytes < 10 ? self::decimal($megabytes) : (string) (int) round($megabytes));
        }

        return \sprintf('%s Go', self::decimal($bytes / self::KILO ** 3));
    }

    /** French writes 1,5 - and this string is read by a human, never parsed back. */
    private static function decimal(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 1));
    }
}
