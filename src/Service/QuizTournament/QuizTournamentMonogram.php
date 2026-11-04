<?php

declare(strict_types=1);

namespace App\Service\QuizTournament;

/**
 * The monogram drawn where a tournament without a logo would show one (design/validated/quiz-tournament.md
 * §10.1), from its name - a pure function.
 *
 * Every name says « tournoi », so that word and the little words around it are left out. Then: an
 * acronym of the name (« Tournoi RGPD » → RGPD) is the monogram; else one word gives its first three
 * letters (« Tournoi Réseau » → RÉS), several words their initials, three at most (« Tournoi des bases
 * de données » → BD). A name made of nothing else falls back on its own first letters.
 */
final class QuizTournamentMonogram
{
    private const int MAX_LETTERS = 3;
    private const int MAX_ACRONYM = 4;
    private const array SKIPPED = ['tournoi', 'tournois', 'grand', 'grande', 'de', 'du', 'des', 'd', 'la', 'le', 'les', 'l', 'et', 'en', 'au', 'aux', 'un', 'une'];

    public static function of(string $name): string
    {
        $words = preg_split('/[^\\p{L}\\p{N}]+/u', $name, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $words) {
            return '?';
        }

        $kept = array_values(array_filter(
            $words,
            static fn (string $word): bool => !\in_array(mb_strtolower($word), self::SKIPPED, true) && !preg_match('/^\d+$/', $word),
        ));

        foreach ($kept as $word) {
            $length = mb_strlen($word);
            if ($length >= 2 && $length <= self::MAX_ACRONYM && $word === mb_strtoupper($word) && preg_match('/\p{L}/u', $word)) {
                return $word;
            }
        }

        if ([] === $kept) {
            return mb_strtoupper(mb_substr($words[0], 0, self::MAX_LETTERS));
        }

        if (1 === \count($kept)) {
            return mb_strtoupper(mb_substr($kept[0], 0, self::MAX_LETTERS));
        }

        return mb_strtoupper(implode('', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            \array_slice($kept, 0, self::MAX_LETTERS),
        )));
    }
}
