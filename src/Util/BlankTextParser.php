<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Reads a QuestionType::TexteATrous statement, where a blank is written as three dots - screens
 * 2a-2d of design/design_handoff_quiz. Static (like DurationFormatter) because the exact same
 * parse has to happen in four places that must never disagree on "how many blanks are there":
 * the Stimulus editor's live counter, the server-side save, the passation renderer, and the grader.
 *
 * macOS and most editors silently turn a typed "..." into the single ellipsis character "…", so
 * both spellings mark a blank - the same class of Unicode substitution trap as the curly apostrophe
 * in the Notion import. A run of three or more dots is one blank, not several.
 */
final class BlankTextParser
{
    private const BLANK_PATTERN = '/(?:\.{3,}|\x{2026}+)/u';

    /**
     * The statement split into literal text and blanks, in reading order. Blank items carry the
     * 0-based blank number the rest of the module indexes answers/responses by.
     *
     * @return list<array{type: 'text'|'blank', value: string, index: int}>
     */
    public static function segments(?string $text): array
    {
        $parts = preg_split(self::BLANK_PATTERN, (string) $text);
        if (false === $parts) {
            return [];
        }

        $segments = [];
        foreach ($parts as $i => $part) {
            if ('' !== $part) {
                $segments[] = ['type' => 'text', 'value' => $part, 'index' => -1];
            }
            // Every gap between two parts is one blank - there is no blank after the last part.
            if ($i < \count($parts) - 1) {
                $segments[] = ['type' => 'blank', 'value' => '', 'index' => $i];
            }
        }

        return $segments;
    }

    public static function countBlanks(?string $text): int
    {
        return preg_match_all(self::BLANK_PATTERN, (string) $text) ?: 0;
    }

    /**
     * The few words around a blank, as shown greyed next to "Trou 2" on screen 2b
     * ("…répartis en ___ octets…"). Returns null when the blank does not exist.
     */
    public static function context(?string $text, int $blankIndex, int $words = 3): ?string
    {
        $segments = self::segments($text);
        $before = '';
        $after = '';
        $found = false;

        foreach ($segments as $i => $segment) {
            if ('blank' !== $segment['type'] || $segment['index'] !== $blankIndex) {
                continue;
            }
            $found = true;
            $before = ($segments[$i - 1] ?? null)['type'] === 'text' ? $segments[$i - 1]['value'] : '';
            $after = ($segments[$i + 1] ?? null)['type'] === 'text' ? $segments[$i + 1]['value'] : '';
            break;
        }

        if (!$found) {
            return null;
        }

        $beforeWords = preg_split('/\s+/u', trim($before), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $afterWords = preg_split('/\s+/u', trim($after), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $left = implode(' ', \array_slice($beforeWords, -$words));
        $right = implode(' ', \array_slice($afterWords, 0, $words));

        return trim(($left ? '… '.$left : '').' ___ '.($right ? $right.' …' : ''));
    }

    /**
     * Whether what the student typed/placed counts as one of the blank's accepted variants.
     * Surrounding whitespace and repeated inner spaces never matter (a student who types " 32"
     * answered 32); case and accents only stop mattering when the teacher opted in, and
     * $tolerateTypo additionally accepts a Levenshtein distance of 1.
     *
     * @param list<string> $variants
     */
    public static function matches(?string $submitted, array $variants, bool $ignoreCase, bool $tolerateTypo): bool
    {
        $needle = self::normalize($submitted, $ignoreCase);
        if ('' === $needle) {
            return false;
        }

        foreach ($variants as $variant) {
            $candidate = self::normalize($variant, $ignoreCase);
            if ('' === $candidate) {
                continue;
            }
            if ($needle === $candidate) {
                return true;
            }
            if ($tolerateTypo && self::distance($needle, $candidate) <= 1) {
                return true;
            }
        }

        return false;
    }

    public static function normalize(?string $value, bool $ignoreCase): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        if (!$ignoreCase) {
            return $value;
        }

        $value = mb_strtolower($value, 'UTF-8');

        // Decompose then drop the combining marks: "É" -> "E" + U+0301 -> "e". Beats
        // iconv//TRANSLIT, whose output depends on the container's locale.
        $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);
        if (\is_string($decomposed)) {
            $value = preg_replace('/\p{Mn}/u', '', $decomposed) ?? $value;
        }

        return $value;
    }

    /**
     * Levenshtein distance in characters, not bytes - PHP's native levenshtein() counts an "é" as
     * two edits, which would make "tolérer une faute de frappe" behave differently on accented
     * answers than on plain ones. Inputs here are single blank answers, so the O(n·m) table is
     * never a concern.
     */
    private static function distance(string $a, string $b): int
    {
        $left = preg_split('//u', $a, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $right = preg_split('//u', $b, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $lenLeft = \count($left);
        $lenRight = \count($right);

        if (0 === $lenLeft) {
            return $lenRight;
        }
        if (0 === $lenRight) {
            return $lenLeft;
        }

        $previous = range(0, $lenRight);
        for ($i = 1; $i <= $lenLeft; ++$i) {
            $current = [$i];
            for ($j = 1; $j <= $lenRight; ++$j) {
                $cost = $left[$i - 1] === $right[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }
            $previous = $current;
        }

        return $previous[$lenRight];
    }
}
