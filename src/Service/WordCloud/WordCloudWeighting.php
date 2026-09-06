<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Enum\WordCloudScale;

/**
 * How big and how bright each word of a cloud is drawn, and in what order the words are laid out
 * (design_handoff_nuage_de_mots, « Règle de pondération du nuage »).
 *
 *     size = lo + sqrt(count / max) * (hi - lo)
 *
 * The square root, rather than a straight ratio, is what keeps a word cited once legible next to
 * one cited twenty times: the area of the drawn word grows about linearly with the citations while
 * its height does not.
 *
 * The layout is deliberately the simplest thing that works: no rotation, no collision solver. The
 * words are dealt alternately to the front and the back of a list which is then rendered in a
 * centred `flex-wrap`, so the most cited ones end up in the middle of the block. Anything cleverer
 * would move words between two refreshes of a live board, which is the one thing a projection must
 * not do.
 */
class WordCloudWeighting
{
    public function size(int $count, int $max, WordCloudScale $scale): int
    {
        if ($max <= 0) {
            return $scale->minSize();
        }

        $lo = $scale->minSize();
        $hi = $scale->maxSize();

        return (int) round($lo + sqrt($count / $max) * ($hi - $lo));
    }

    /**
     * Which rung of the handoff's colour ladder a word sits on - and only that.
     *
     * The two palettes it names (one for the dark board, one for a light ground) are in the
     * stylesheet, because which of them applies is a question about the theme: the pilot preview is
     * a white card in the light theme and a dark one in the other, and a hex chosen here would have
     * been wrong in whichever of the two was not the one it was written for.
     *
     * @return 'top'|'strong'|'mid'|'faint'
     */
    public function step(int $count, int $max): string
    {
        return match (true) {
            $count >= $max => 'top',
            $count >= 4 => 'strong',
            $count >= 2 => 'mid',
            default => 'faint',
        };
    }

    /**
     * @param list<array{word: string, count: int}> $words most cited first, as WordCloudAggregator
     *                                                     hands them back
     *
     * @return list<array{word: string, count: int, size: int, step: string}> in drawing order
     */
    public function layout(array $words, WordCloudScale $scale): array
    {
        if ([] === $words) {
            return [];
        }

        $max = $words[0]['count'];

        $ordered = [];
        foreach ($words as $index => $word) {
            $drawn = [
                'word' => $word['word'],
                'count' => $word['count'],
                'size' => $this->size($word['count'], $max, $scale),
                'step' => $this->step($word['count'], $max),
            ];

            if (0 === $index % 2) {
                array_unshift($ordered, $drawn);
            } else {
                $ordered[] = $drawn;
            }
        }

        return $ordered;
    }
}
