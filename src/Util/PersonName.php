<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Reads a "NOM Prénom" cell the way a human does, and compares two names the way a human would.
 *
 * Written for the UFA contract import (App\Service\AlternanceImport), whose spreadsheet names the
 * student and the tutor in one free-text column - the only handle on an account that must already
 * exist, or on one about to be created. Both jobs are guesses, and both are deliberately
 * conservative:
 *
 *  - split() trusts CASE, not position: French administrative exports write the surname in capitals
 *    ("BRETONNET Paul", "CHAMPCOMMUNAL--DEMONT Chloé", "DE ANDRADE Nathan" - two words of surname).
 *    Only when the whole cell is capitals, or none of it is, does it fall back to "first word is
 *    the surname", which is that same export's other habit ("CUQUEMELLE JEAN FRANCOIS").
 *  - fold() throws away everything a database and a spreadsheet can legitimately disagree about:
 *    accents, case, hyphens, double spaces, and the ORDER of the words. "DE ANDRADE Nathan" and
 *    "Nathan de Andrade" fold to the same key, which is the point - the import must not create a
 *    second account for someone it merely failed to recognise.
 */
final class PersonName
{
    /**
     * @return array{lastname: string, firstname: string}
     */
    public static function split(string $full): array
    {
        $words = preg_split('/\s+/', trim($full), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if ([] === $words) {
            return ['lastname' => '', 'firstname' => ''];
        }

        $upper = [];
        $rest = [];
        foreach ($words as $word) {
            if (self::isUpperCase($word)) {
                $upper[] = $word;
            } else {
                $rest[] = $word;
            }
        }

        // Mixed case: the capitals are the surname, whatever their position.
        if ([] !== $upper && [] !== $rest) {
            return ['lastname' => implode(' ', $upper), 'firstname' => implode(' ', $rest)];
        }

        return [
            'lastname' => $words[0],
            'firstname' => implode(' ', \array_slice($words, 1)),
        ];
    }

    /**
     * A comparison key: lowercase ASCII words, deduplicated separators, sorted so word order stops
     * mattering. Two names sharing a key are the same person as far as the import is concerned.
     */
    public static function fold(string ...$parts): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', implode(' ', $parts));
        $letters = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(false !== $ascii ? $ascii : implode(' ', $parts))) ?? '';

        $words = preg_split('/\s+/', trim($letters), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
    }

    /** True for a word written entirely in capitals - accented letters included, digits ignored. */
    private static function isUpperCase(string $word): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $word) ?? '';

        return '' !== $letters && $letters === mb_strtoupper($letters);
    }
}
