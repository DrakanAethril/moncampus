<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

/**
 * What a cloud looks like right now: the words and their counts, the last few to arrive, the
 * ranking, and the three figures of the meta line.
 *
 * @phpstan-import-type WordCloudWord from WordCloudBoard
 * @phpstan-import-type WordCloudLatest from WordCloudBoard
 * @phpstan-import-type WordCloudTop from WordCloudBoard
 */
final readonly class WordCloudBoardData
{
    /**
     * @param list<WordCloudWord>   $words  most cited first
     * @param list<WordCloudLatest> $latest most recent first
     * @param list<WordCloudTop>    $top    the five most cited, with their share of the maximum
     */
    public function __construct(
        public array $words,
        public array $latest,
        public array $top,
        public int $distinctWords,
        public int $submissionCount,
        public int $participantCount,
        public int $pendingCount,
    ) {
    }

    public function mostCitedWord(): ?string
    {
        return $this->words[0]['word'] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->words;
    }
}
