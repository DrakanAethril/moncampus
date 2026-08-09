<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Accent-insensitive search over the help index, done in PHP over rows the caller has already read.
 *
 * The whole index is a few hundred short rows loaded in one query, so the interesting part is not
 * where the matching runs but what it has to produce: a French reader typing "ecoute" must find
 * "écoute", and the result list shows an excerpt with every occurrence marked
 * (design_handoff_aide, écran 3).
 *
 * Marking is returned as segments, never as HTML: the template escapes each segment and wraps the
 * matching ones itself, so no user- or admin-authored text is ever handed to Twig raw.
 *
 * @phpstan-type SearchRow array{key: string, title: string, text: string}
 * @phpstan-type Segment array{text: string, match: bool}
 * @phpstan-type SearchHit array{key: string, score: int, excerpt: list<Segment>}
 */
class HelpSearch
{
    private const int EXCERPT_LENGTH = 180;

    // How far before the first match the excerpt starts, so the term is not glued to the ellipsis.
    private const int EXCERPT_LEAD = 40;

    // Deliberately one character per character: the normalized string keeps the same character
    // offsets as the original, which is what lets a match found on the normalized text be cut out
    // of the original with its accents intact.
    private const array FOLDED = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'œ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        '’' => "'", '‘' => "'", '–' => '-', '—' => '-', ' ' => ' ',
    ];

    /**
     * @param list<SearchRow> $rows
     *
     * @return list<SearchHit> the matching rows, best first
     */
    public function search(string $query, array $rows): array
    {
        $terms = $this->terms($query);

        if ([] === $terms) {
            return [];
        }

        $hits = [];

        foreach ($rows as $row) {
            $title = $this->fold($row['title']);
            $text = $this->fold($row['text']);
            $score = 0;

            foreach ($terms as $term) {
                $inTitle = str_contains($title, $term);
                $inText = str_contains($text, $term);

                if (!$inTitle && !$inText) {
                    continue 2;
                }

                $score += $inTitle ? 10 : 1;
                // A title that opens on the term is what the reader was most likely after.
                $score += str_starts_with($title, $term) ? 5 : 0;
            }

            $hits[] = [
                'key' => $row['key'],
                'score' => $score,
                'excerpt' => $this->excerpt($row['text'], $terms),
            ];
        }

        // usort has been stable since PHP 8.0, so rows of equal score keep the order the caller
        // read them in - which is the help's own section/position order, not an arbitrary one.
        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $hits;
    }

    /**
     * The text cut into marked and unmarked segments - used for titles, which are shown whole.
     *
     * @return list<Segment>
     */
    public function segments(string $text, string $query): array
    {
        return $this->mark($text, $this->terms($query));
    }

    /**
     * @param list<string> $terms
     *
     * @return list<Segment>
     */
    private function excerpt(string $text, array $terms): array
    {
        $folded = $this->fold($text);
        $start = 0;

        foreach ($terms as $term) {
            $position = mb_strpos($folded, $term);
            if (false !== $position) {
                $start = max(0, $position - self::EXCERPT_LEAD);
                break;
            }
        }

        if (0 === $start && mb_strlen($text) <= self::EXCERPT_LENGTH) {
            return $this->mark($text, $terms);
        }

        // Do not cut a word in half on the left edge.
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = false === $space ? $start : $space + 1;
        }

        $cut = mb_substr($text, $start, self::EXCERPT_LENGTH);
        $segments = $this->mark($cut, $terms);

        if ($start > 0) {
            array_unshift($segments, ['text' => '…', 'match' => false]);
        }
        if ($start + self::EXCERPT_LENGTH < mb_strlen($text)) {
            $segments[] = ['text' => '…', 'match' => false];
        }

        return $segments;
    }

    /**
     * @param list<string> $terms
     *
     * @return list<Segment>
     */
    private function mark(string $text, array $terms): array
    {
        if ([] === $terms || '' === $text) {
            return '' === $text ? [] : [['text' => $text, 'match' => false]];
        }

        $folded = $this->fold($text);
        $length = mb_strlen($text);

        // Character-by-character map of what is inside a match, built term by term: overlapping
        // occurrences of two different terms then merge on their own instead of nesting.
        $marked = array_fill(0, $length, false);

        foreach ($terms as $term) {
            $termLength = mb_strlen($term);
            $offset = 0;

            while (false !== ($position = mb_strpos($folded, $term, $offset))) {
                // A term matches a whole word or the start of one, never its middle: searching
                // "note" must not light up the "note" inside "dénoter".
                if (0 === $position || !$this->isWordCharacter(mb_substr($folded, $position - 1, 1))) {
                    $end = $this->wordEnd($folded, $position + $termLength);
                    for ($i = $position; $i < $end; ++$i) {
                        $marked[$i] = true;
                    }
                }

                $offset = $position + $termLength;
            }
        }

        $segments = [];
        $buffer = '';
        $current = false;

        for ($i = 0; $i < $length; ++$i) {
            if ($marked[$i] !== $current && '' !== $buffer) {
                $segments[] = ['text' => $buffer, 'match' => $current];
                $buffer = '';
            }

            $current = $marked[$i];
            $buffer .= mb_substr($text, $i, 1);
        }

        if ('' !== $buffer) {
            $segments[] = ['text' => $buffer, 'match' => $current];
        }

        return $segments;
    }

    /** Where the word holding position $from ends, so "écoute" marks the whole of "écouté". */
    private function wordEnd(string $folded, int $from): int
    {
        $length = mb_strlen($folded);

        while ($from < $length && $this->isWordCharacter(mb_substr($folded, $from, 1))) {
            ++$from;
        }

        return $from;
    }

    private function isWordCharacter(string $character): bool
    {
        return 1 === preg_match('/[\p{L}\p{N}]/u', $character);
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $words = preg_split('/\s+/u', trim($this->fold($query))) ?: [];

        return array_values(array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    private function fold(string $value): string
    {
        return strtr(mb_strtolower($value), self::FOLDED);
    }
}
