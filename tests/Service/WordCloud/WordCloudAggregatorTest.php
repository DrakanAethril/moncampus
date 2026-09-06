<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Service\WordCloud\WordCloudAggregator;
use App\Service\WordCloud\WordCloudNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Submissions in, the cloud's word list out: how many times each word was cited, and which spelling
 * of it the board shows.
 *
 * Everything here is decided on primitives - a text and a key - because the same arithmetic serves
 * the pilot preview, both projections and the two follow-up views, and none of them should have to
 * agree about entities to agree about counts.
 */
class WordCloudAggregatorTest extends TestCase
{
    public function testAnEmptyCloudAggregatesToNothing(): void
    {
        self::assertSame([], $this->aggregator()->aggregate([]));
    }

    public function testWordsAreCountedAndOrderedByCitations(): void
    {
        $words = $this->aggregator()->aggregate($this->rows(['pare-feu', 'phishing', 'phishing', 'phishing', 'pare-feu']));

        self::assertSame([
            ['word' => 'phishing', 'count' => 3],
            ['word' => 'pare-feu', 'count' => 2],
        ], $words);
    }

    /**
     * Two words cited as often as each other keep the order they arrived in - the projection is
     * redrawn on every submission, and a cloud that reshuffles its ties at each refresh is
     * unreadable at the back of the room.
     */
    public function testATieKeepsTheOrderOfFirstArrival(): void
    {
        $words = $this->aggregator()->aggregate($this->rows(['VPN', 'RGPD']));

        self::assertSame(['VPN', 'RGPD'], array_column($words, 'word'));
    }

    /** The variants group, and the board shows the spelling the class actually wrote most often. */
    public function testTheDisplayedSpellingIsTheMostFrequentOfTheGroup(): void
    {
        $words = $this->aggregator()->aggregate($this->rows(['Pare-feu', 'pare feu', 'pare feu']));

        self::assertSame([['word' => 'pare feu', 'count' => 3]], $words);
    }

    public function testAnEquallyFrequentSpellingLosesToTheOneWrittenFirst(): void
    {
        $words = $this->aggregator()->aggregate($this->rows(['Pare-feu', 'pare feu']));

        self::assertSame([['word' => 'Pare-feu', 'count' => 2]], $words);
    }

    public function testGroupingVariantsIsWhatMergesCaseAndAccents(): void
    {
        self::assertSame('securite', $this->aggregator()->key('Sécurité', true));
    }

    /** Grouping off: the exact string, trimmed and nothing else - two spellings, two words. */
    public function testWithoutGroupingTheKeyIsTheTrimmedStringItself(): void
    {
        self::assertSame('Sécurité', $this->aggregator()->key('  Sécurité ', false));

        $words = $this->aggregator()->aggregate([
            ['text' => 'Sécurité', 'key' => 'Sécurité'],
            ['text' => 'sécurité', 'key' => 'sécurité'],
        ]);

        self::assertSame([
            ['word' => 'Sécurité', 'count' => 1],
            ['word' => 'sécurité', 'count' => 1],
        ], $words);
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{text: string, key: string}>
     */
    private function rows(array $texts): array
    {
        $aggregator = $this->aggregator();

        return array_map(
            static fn (string $text): array => ['text' => $text, 'key' => $aggregator->key($text, true)],
            $texts,
        );
    }

    private function aggregator(): WordCloudAggregator
    {
        return new WordCloudAggregator(new WordCloudNormalizer());
    }
}
