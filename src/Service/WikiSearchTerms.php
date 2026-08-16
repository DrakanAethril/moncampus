<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns what somebody typed into the rail's search box into a MySQL FULLTEXT boolean-mode query.
 *
 * Boolean mode rather than natural language, for two reasons: "adressage ip" then means *both*
 * words rather than either, and a trailing `*` makes it a prefix search - nobody types the whole
 * word they are looking for.
 *
 * The price of boolean mode is that its own operators (`+ - * " ( ) ~ < > @`) are syntax, and a
 * stray one is an SQL error rather than a search: a person typing "plug-and-play" would otherwise
 * be asking the engine to *exclude* "and" and "play", and an unbalanced quote would take the screen
 * down. So the typed text is reduced to words, and this class builds every operator itself.
 */
final class WikiSearchTerms
{
    /**
     * Enough for any real query. The cap is not about performance so much as about the shape of an
     * accident - a pasted paragraph would otherwise become a 200-clause boolean query.
     */
    public const int MAX_WORDS = 8;

    public static function forBooleanMode(string $typed): string
    {
        // Split on anything that is not a letter, a digit or an underscore. Accents survive (\p{L}
        // with the /u flag), because the column's collation is what makes the search
        // accent-insensitive - folding here would only stop "réseau" from matching itself.
        $words = preg_split('/[^\p{L}\p{N}_]+/u', trim($typed), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = \array_slice($words, 0, self::MAX_WORDS);

        return implode(' ', array_map(static fn (string $word): string => '+'.$word.'*', $words));
    }
}
