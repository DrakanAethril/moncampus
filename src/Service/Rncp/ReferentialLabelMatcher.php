<?php

declare(strict_types=1);

namespace App\Service\Rncp;

/**
 * Compares two labels of a référentiel as a person would: without case, accents, typographic
 * apostrophes, punctuation or doubled spaces.
 *
 * Two places need it, for the same reason - codes are not stable, words are:
 *
 * - matching a block across two versions of a fiche (RNCP35340 → RNCP40792 renumbered SLAM's block
 *   from BC04 to BC03);
 * - matching the columns of an uploaded official template against the synthesis block (the .xlsx
 *   writes « d’assistance » with a curly apostrophe, a hand-typed référentiel may not).
 */
final class ReferentialLabelMatcher
{
    public static function normalize(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = str_replace(['’', '‘', '`', 'œ', 'æ'], ["'", "'", "'", 'oe', 'ae'], $label);
        $ascii = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($label);
        $label = \is_string($ascii) ? $ascii : $label;
        $label = (string) preg_replace('/[^a-z0-9]+/u', ' ', $label);

        return trim($label);
    }

    public static function same(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /** Does `$haystack` contain `$needle`, both normalised? For a cell that adds words around a label. */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::normalize($needle);

        return '' !== $needle && str_contains(self::normalize($haystack), $needle);
    }
}
