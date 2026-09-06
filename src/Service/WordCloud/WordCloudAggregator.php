<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

/**
 * Submissions in, the cloud's word list out - the single arithmetic behind the pilot preview, both
 * projections and the two follow-up views.
 *
 * It works on primitives (a raw text and its aggregation key) rather than on WordCloudSubmission,
 * so that the same counting serves a live SSE payload, a CSV row and a PDF without any of them
 * having to hold entities. key() is the one place that knows what « Regrouper les variantes »
 * means; aggregate() never reads the setting again.
 */
class WordCloudAggregator
{
    public function __construct(private readonly WordCloudNormalizer $normalizer)
    {
    }

    /**
     * The key two submissions must share to be one word on the board.
     *
     * Grouping off, that is the trimmed string itself: « Sécurité » and « sécurité » are then two
     * words, deliberately.
     */
    public function key(string $text, bool $groupVariants): string
    {
        return $groupVariants ? $this->normalizer->normalize($text) : trim($text);
    }

    /**
     * @param list<array{text: string, key: string}> $submissions in chronological order
     *
     * @return list<array{word: string, count: int}> most cited first
     */
    public function aggregate(array $submissions): array
    {
        /** @var array<string, array{count: int, spellings: array<string, int>}> $groups */
        $groups = [];

        foreach ($submissions as $submission) {
            $key = $submission['key'];
            $text = $submission['text'];

            $groups[$key] ??= ['count' => 0, 'spellings' => []];
            ++$groups[$key]['count'];
            $groups[$key]['spellings'][$text] = ($groups[$key]['spellings'][$text] ?? 0) + 1;
        }

        $words = [];
        foreach ($groups as $group) {
            $words[] = ['word' => $this->displaySpelling($group['spellings']), 'count' => $group['count']];
        }

        // Stable since PHP 8.0, and relied upon: words cited as often as each other keep the order
        // they first arrived in. The projection is redrawn at every submission, and ties that
        // reshuffle on each pass make the board unreadable.
        usort($words, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $words;
    }

    /**
     * @param array<string, int> $spellings raw text => times written, in order of first appearance
     */
    private function displaySpelling(array $spellings): string
    {
        $best = '';
        $bestCount = 0;

        foreach ($spellings as $text => $count) {
            // Strictly greater: an equally frequent spelling loses to the one written first, so the
            // board does not swap wording halfway through an activity.
            if ($count > $bestCount) {
                $best = (string) $text;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
