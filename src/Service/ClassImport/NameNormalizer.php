<?php

declare(strict_types=1);

namespace App\Service\ClassImport;

/**
 * Rewrites the "DUPONT Martin" and "dupont martin" a secretariat's spreadsheet produces into the
 * spelling a directory entry can carry.
 *
 * The rule that makes this safe is the second one: a word that already mixes cases carries a
 * decision somebody made - MacLeod, McDonald, d'Arcy - and is returned untouched. Only a word
 * written entirely in capitals or entirely in lowercase says nothing about its own spelling, and
 * only those are rewritten. Firstname and lastname become the directory's property and are
 * read-only on the user's own record afterwards, so a wrong normalisation cannot be repaired from
 * the application - which is also why the verification screen shows the normalised name before
 * anything is written.
 */
final class NameNormalizer
{
    /** Lowercase everywhere but at the head of the name: "GOUBAULT DE BRUGIERE" -> "Goubault de Brugiere". */
    private const array PARTICLES = [
        'de', 'du', 'des', 'le', 'la', 'van', 'von', 'der', 'den', 'di', 'da', 'del', 'della', 'dos',
    ];

    /** Recapitalised in their own right, so "jean-baptiste" becomes "Jean-Baptiste". */
    private const array SEPARATORS = ['-', "'", '’'];

    public function normalize(string $value): string
    {
        $words = preg_split('/\s+/u', trim($value), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words || [] === $words) {
            return '';
        }

        $normalized = [];
        foreach ($words as $index => $word) {
            $normalized[] = $this->normalizeWord($word, 0 === $index);
        }

        return implode(' ', $normalized);
    }

    /** Whether normalising would change the spelling - what the verification screen flags. */
    public function differs(string $value): bool
    {
        return $this->normalize($value) !== trim($value);
    }

    private function normalizeWord(string $word, bool $isFirst): string
    {
        // Checked before the particle rule on purpose: "De Brugiere" typed that way is a spelling
        // its author chose, whereas "DE BRUGIERE" is a spreadsheet shouting.
        if ($this->isMixedCase($word)) {
            return $word;
        }

        if (!$isFirst && \in_array(mb_strtolower($word), self::PARTICLES, true)) {
            return mb_strtolower($word);
        }

        return $this->capitalizeSegments($word);
    }

    private function isMixedCase(string $word): bool
    {
        return mb_strtoupper($word) !== $word && mb_strtolower($word) !== $word;
    }

    private function capitalizeSegments(string $word): string
    {
        $pattern = '/(['.preg_quote(implode('', self::SEPARATORS), '/').'])/u';
        $parts = preg_split($pattern, $word, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $word;
        }

        $rebuilt = '';
        foreach ($parts as $part) {
            $rebuilt .= \in_array($part, self::SEPARATORS, true) ? $part : $this->capitalize($part);
        }

        return $rebuilt;
    }

    private function capitalize(string $part): string
    {
        if ('' === $part) {
            return '';
        }

        return mb_strtoupper(mb_substr($part, 0, 1)).mb_strtolower(mb_substr($part, 1));
    }
}
