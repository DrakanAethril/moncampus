<?php

declare(strict_types=1);

namespace App\Service\Equipment;

/**
 * The code on the label of a unit-tracked piece of equipment: `CA-0142-0`.
 *
 * - `CA`, a fixed prefix, so a label found on a desk says which inventory it belongs to;
 * - one number drawn from a **single sequence shared by every type**, padded to four digits as a
 *   minimum and never reused (App\Service\Equipment\EquipmentCodeAllocator). It carries no
 *   category and no place: a code that means nothing cannot become false when a type is renamed;
 * - a Luhn check digit on that number.
 *
 * The labeller is a basic keyboard Dymo, so the code is typed by hand, and typed again into the
 * search field when somebody holds the object. The check digit is what catches a typo made on the
 * Dymo - without it, a wrong key prints another valid code and the label points at a stranger for
 * ever. candidates() therefore never corrects a wrong digit: it hands it back flagged, and the
 * screen says the label or the typing is wrong.
 */
final class EquipmentCode
{
    public const string PREFIX = 'CA';

    public static function format(int $number): string
    {
        return \sprintf('%s-%04d-%d', self::PREFIX, $number, self::checkDigit($number));
    }

    public static function checkDigit(int $number): int
    {
        $sum = 0;
        // The rightmost digit of the payload is the one doubled, the check digit being appended after it.
        foreach (array_reverse(str_split((string) $number)) as $position => $digit) {
            $value = (int) $digit;
            if (0 === $position % 2) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * The readings of a search input as a code, or null when it does not read as one at all (a
     * type name, say) and the caller should search by text instead.
     *
     * Case, spaces, dashes, the prefix and leading zeros are all ignored. A last group of one digit
     * after a separator is the check digit. Digits typed with no separator are ambiguous past a
     * thousand pieces - `01420` is 1420, or 142 followed by its check digit - so both readings are
     * offered, the second only when its digit actually checks.
     *
     * @return list<EquipmentCodeCandidate>|null
     */
    public static function candidates(string $input): ?array
    {
        $normalized = strtoupper(trim($input));

        if (1 !== preg_match('/^(?:'.self::PREFIX.')?[\s-]*(\d+)(?:[\s-]+(\d))?$/', $normalized, $matches)) {
            return null;
        }

        $digits = $matches[1];

        if (isset($matches[2])) {
            $candidates = [new EquipmentCodeCandidate((int) $digits, (int) $matches[2])];
        } else {
            $candidates = [new EquipmentCodeCandidate((int) $digits, null)];

            if (\strlen($digits) >= 2) {
                $withCheck = new EquipmentCodeCandidate((int) substr($digits, 0, -1), (int) substr($digits, -1));
                if ($withCheck->isValid()) {
                    $candidates[] = $withCheck;
                }
            }
        }

        return array_values(array_filter($candidates, static fn (EquipmentCodeCandidate $candidate): bool => $candidate->number > 0));
    }
}
