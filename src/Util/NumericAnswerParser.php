<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Reads what a student typed into a numeric answer field: a number, possibly with a unit after it.
 *
 * Written for French keyboards and French habits, which is where every rule here comes from:
 * "2,5" is two and a half, "1 234,5" carries a thousands space (often a non-breaking one, since
 * that is what a phone keyboard and a copy-paste from a spreadsheet produce), and "240 km" is a
 * perfectly reasonable thing to type even when the unit was not asked for. None of that should
 * cost a student a right answer, so all of it is accepted and normalised here rather than being
 * pushed onto a regex in a template.
 */
final class NumericAnswerParser
{
    /**
     * @return array{value: ?float, unit: ?string} value is null when there is no number to read at
     *                                             all; unit is whatever followed it, trimmed
     */
    public static function parse(string $raw): array
    {
        // Every space-ish character a keyboard, a phone or a spreadsheet can produce between digits.
        $clean = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", ' ', "\t"], '', trim($raw));
        if ('' === $clean) {
            return ['value' => null, 'unit' => null];
        }

        $clean = self::normaliseSeparators($clean);

        // The number is the longest numeric prefix; anything after it is the unit. Scientific
        // notation is included so "1.2e3" and a pasted spreadsheet value both read.
        if (1 !== preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/', $clean, $matches)) {
            return ['value' => null, 'unit' => null];
        }

        $number = $matches[0];
        $unit = trim(substr($clean, \strlen($number)));

        return [
            'value' => is_numeric($number) ? (float) $number : null,
            'unit' => '' !== $unit ? $unit : null,
        ];
    }

    /**
     * Whether two unit strings mean the same thing. Case and surrounding spaces are forgiven -
     * "KM" and "km" are the same unit, and holding a student to the capitalisation would be
     * marking their keyboard rather than their physics.
     */
    public static function unitsMatch(?string $expected, ?string $given): bool
    {
        $normalise = static fn (?string $unit): string => mb_strtolower(trim((string) $unit));

        return $normalise($expected) === $normalise($given);
    }

    /**
     * Decides which of "," and "." is the decimal separator and leaves a PHP-readable number.
     *
     * The rule is "the last one wins", which resolves the genuinely ambiguous cases the same way a
     * reader would: "1,234.5" is English (comma groups, dot decimal), "1.234,5" is French, and a
     * lone "," is always a decimal separator because nobody types a thousands comma on its own.
     */
    private static function normaliseSeparators(string $clean): string
    {
        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if (false === $lastComma) {
            return $clean;
        }

        if (false === $lastDot) {
            // Only commas: the last is the decimal point, earlier ones group digits.
            return substr_replace(str_replace(',', '', $clean), '.', self::offsetAfterRemoving($clean, $lastComma), 0);
        }

        return $lastComma > $lastDot
            ? str_replace(',', '.', str_replace('.', '', $clean))   // French: 1.234,5
            : str_replace(',', '', $clean);                          // English: 1,234.5
    }

    /** Where $position lands once every comma before it has been removed. */
    private static function offsetAfterRemoving(string $clean, int $position): int
    {
        return $position - substr_count(substr($clean, 0, $position), ',');
    }
}
