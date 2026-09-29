<?php

declare(strict_types=1);

namespace App\Service\Sirene;

/**
 * The SIRET as a number: what it looks like typed, whether it can exist, and how it reads.
 *
 * Fourteen digits - the nine of the SIREN (the company) and five for the establishment - of which
 * the last is a Luhn check digit over the whole. La Poste is the one exception INSEE publishes: its
 * establishments are too many for five digits under Luhn, so for SIREN 356000000 the digits adding
 * up to a multiple of five also passes - its siège (35600000000048) is an ordinary Luhn number.
 *
 * isValid() says whether a number *can* be a SIRET, never whether it is the right one for an
 * employer - that is the État's register to answer (RechercheEntreprisesClient) and a person to
 * decide (design/validated/siret-entreprises.md, R1).
 */
final class Siret
{
    public const string LA_POSTE_SIREN = '356000000';

    /** The number without the spaces, dots and dashes it is often typed with (`489 319 103 00037`). */
    public static function normalize(string $value): string
    {
        return preg_replace('/[\s.\-]+/u', '', $value) ?? $value;
    }

    public static function isValid(string $value): bool
    {
        $siret = self::normalize($value);

        if (1 !== preg_match('/^\d{14}$/', $siret)) {
            return false;
        }

        if (str_starts_with($siret, self::LA_POSTE_SIREN)) {
            return self::luhn($siret) || 0 === array_sum(array_map('intval', str_split($siret))) % 5;
        }

        return self::luhn($siret);
    }

    /** `489 319 103 00037`: the SIREN in threes, then the establishment's five digits. */
    public static function format(string $value): string
    {
        $siret = self::normalize($value);

        if (1 !== preg_match('/^(\d{3})(\d{3})(\d{3})(\d{5})$/', $siret, $groups)) {
            return $value;
        }

        return \sprintf('%s %s %s %s', $groups[1], $groups[2], $groups[3], $groups[4]);
    }

    public static function siren(string $value): string
    {
        return substr(self::normalize($value), 0, 9);
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        // From the right: the check digit itself counts once, the one before it is doubled, and so on.
        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $value = (int) $digit;
            if (1 === $position % 2) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return 0 === $sum % 10;
    }
}
