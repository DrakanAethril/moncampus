<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The {name} placeholders a "calculée" statement carries ("Un train roule à {v} km/h pendant {t} h").
 *
 * Same role as App\Util\BlankTextParser for a texte à trous and App\Util\ZoneTextParser for the
 * zones types: the statement is the single source of truth for which variables exist, the editor
 * counts them live from the text, and nothing anywhere re-implements the marker syntax.
 */
final class NumericVariableParser
{
    /**
     * A name is a letter followed by letters, digits or underscores - deliberately narrow, so that
     * a statement containing a literal brace ("{ }" in a code sample, a set in mathematics) is not
     * mistaken for a variable.
     */
    private const string PATTERN = '/\{([a-zA-Z][a-zA-Z0-9_]*)\}/';

    /**
     * Every variable the statement names, in order of first appearance and without repeats.
     *
     * @return list<string>
     */
    public static function names(string $text): array
    {
        if (1 > preg_match_all(self::PATTERN, $text, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * The statement split into literal text and placeholders, so a template can paint the drawn
     * values as chips rather than as plain text.
     *
     * @return list<array{type: 'text'|'variable', value: string, name: string}>
     */
    public static function segments(string $text): array
    {
        $segments = [];
        $offset = 0;

        if (1 > preg_match_all(self::PATTERN, $text, $matches, \PREG_OFFSET_CAPTURE)) {
            return '' === $text ? [] : [['type' => 'text', 'value' => $text, 'name' => '']];
        }

        foreach ($matches[0] as $index => $match) {
            [$placeholder, $position] = $match;
            if ($position > $offset) {
                $segments[] = ['type' => 'text', 'value' => substr($text, $offset, $position - $offset), 'name' => ''];
            }
            $segments[] = ['type' => 'variable', 'value' => $placeholder, 'name' => $matches[1][$index][0]];
            $offset = $position + \strlen($placeholder);
        }

        if ($offset < \strlen($text)) {
            $segments[] = ['type' => 'text', 'value' => substr($text, $offset), 'name' => ''];
        }

        return $segments;
    }

    /**
     * The statement as this student reads it. A placeholder with no value is left as it was written
     * rather than blanked: a statement missing a number reads as a bug the teacher can see, where
     * an empty hole reads as a question with a word missing.
     *
     * @param array<string, string> $values name => the value already formatted for display
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[$match[1]] ?? $match[0],
            $text,
        );
    }
}
