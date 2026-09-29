<?php

declare(strict_types=1);

namespace App\Service\Rncp;

/**
 * Reads the `LISTE_COMPETENCES` of an RNCP block: a text in which each competency is a `* ` bullet
 * followed by its savoir-faire as `o ` bullets.
 *
 * The export has no newlines in that field - the bullets are separated by runs of spaces - so the
 * separators are « whitespace, then `*` or `o`, then whitespace ». An `o` inside a sentence is
 * surrounded by single spaces and letters; a bullet is preceded by at least two spaces or by a line
 * break, which is also how an administrator types the same format by hand in « À la main ».
 *
 * A text in which no competency bullet is found is **not** guessed at: the parser answers an empty
 * list and the screen shows the raw text for a manual entry (§8, « format inconnu jamais deviné »).
 */
final class RncpCompetencyListParser
{
    /**
     * @return list<array{label: string, skills: list<string>}>
     */
    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // A competency bullet: start of text or a line break or two spaces, then `*` or `•`.
        $parts = preg_split('/(?:^|\n|\s{2,})\s*[*•]\s+/u', ' '.$text);

        if (false === $parts || \count($parts) < 2) {
            return [];
        }

        array_shift($parts);
        $competencies = [];

        foreach ($parts as $part) {
            $items = preg_split('/(?:\n|\s{2,})\s*(?:o|-|▸)\s+/u', trim($part));

            if (false === $items) {
                continue;
            }

            $label = self::clean(array_shift($items) ?? '');

            if ('' === $label) {
                continue;
            }

            $skills = [];
            foreach ($items as $item) {
                $skill = self::clean($item);
                if ('' !== $skill) {
                    $skills[] = $skill;
                }
            }

            $competencies[] = ['label' => $label, 'skills' => $skills];
        }

        return $competencies;
    }

    private static function clean(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        // A trailing full stop is the fiche's typography, not part of the savoir-faire.
        return rtrim($value, " \t.");
    }
}
