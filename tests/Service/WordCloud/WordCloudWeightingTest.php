<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Enum\WordCloudScale;
use App\Service\WordCloud\WordCloudWeighting;
use PHPUnit\Framework\TestCase;

/**
 * The size and colour of every word on the board, and the order they are laid out in.
 *
 * The expected numbers below are the handoff's formula worked out by hand on its own sample cloud
 * (11 words, the most cited at 6) rather than the formula written twice: a test that recomputes
 * `lo + sqrt(c/max) * (hi - lo)` would agree with any rounding, including the wrong one.
 */
class WordCloudWeightingTest extends TestCase
{
    public function testTheMostCitedWordIsDrawnAtTheScaleMaximum(): void
    {
        self::assertSame(44, $this->weighting()->size(6, 6, WordCloudScale::PilotPreview));
        self::assertSame(60, $this->weighting()->size(6, 6, WordCloudScale::ProjectionWithPanel));
        self::assertSame(92, $this->weighting()->size(6, 6, WordCloudScale::ProjectionFull));
    }

    public function testSizeFollowsTheSquareRootOfTheShareOfCitations(): void
    {
        // 15 + sqrt(5/6) * 29 = 41.47, 15 + sqrt(3/6) * 29 = 35.51, 15 + sqrt(1/6) * 29 = 26.84.
        self::assertSame(41, $this->weighting()->size(5, 6, WordCloudScale::PilotPreview));
        self::assertSame(36, $this->weighting()->size(3, 6, WordCloudScale::PilotPreview));
        self::assertSame(27, $this->weighting()->size(1, 6, WordCloudScale::PilotPreview));
    }

    public function testTheFullBoardStretchesTheSameSharesFurther(): void
    {
        self::assertSame(86, $this->weighting()->size(5, 6, WordCloudScale::ProjectionFull));
        self::assertSame(73, $this->weighting()->size(3, 6, WordCloudScale::ProjectionFull));
        self::assertSame(53, $this->weighting()->size(1, 6, WordCloudScale::ProjectionFull));
    }

    /** A lone word is the most cited word: it takes the maximum, not the minimum. */
    public function testASingleWordIsDrawnAtTheMaximum(): void
    {
        self::assertSame(44, $this->weighting()->size(1, 1, WordCloudScale::PilotPreview));
    }

    /** The top rung is the most cited word **and everything tied with it**, not one winner. */
    public function testTheTopRungIsSharedByEverythingTiedForFirst(): void
    {
        self::assertSame('top', $this->weighting()->step(6, 6));
        self::assertSame('top', $this->weighting()->step(1, 1));
    }

    public function testTheLadderBelowTheTop(): void
    {
        self::assertSame('strong', $this->weighting()->step(5, 6));
        self::assertSame('mid', $this->weighting()->step(3, 6));
        self::assertSame('mid', $this->weighting()->step(2, 6));
        self::assertSame('faint', $this->weighting()->step(1, 6));
    }

    /**
     * The rung is a rung, not a colour: which palette draws it belongs to the stylesheet, since the
     * pilot preview is a white card in the light theme and a dark one in the other.
     */
    public function testTheRungDoesNotDependOnWhereTheCloudIsDrawn(): void
    {
        foreach (WordCloudScale::cases() as $scale) {
            self::assertSame(44, $this->weighting()->size(6, 6, WordCloudScale::PilotPreview));
            self::assertSame('mid', $this->weighting()->step(2, 6), $scale->value);
        }
    }

    /**
     * The layout rule: sorted by citations, then dealt alternately to the front and to the back of
     * the list, so that a centred flex-wrap puts the biggest words in the middle of the block.
     */
    public function testWordsAreDealtOutwardsFromTheCentre(): void
    {
        $laid = $this->weighting()->layout(
            [
                ['word' => 'a', 'count' => 6],
                ['word' => 'b', 'count' => 6],
                ['word' => 'c', 'count' => 5],
                ['word' => 'd', 'count' => 5],
                ['word' => 'e', 'count' => 1],
            ],
            WordCloudScale::ProjectionFull,
        );

        self::assertSame(['e', 'c', 'a', 'b', 'd'], array_column($laid, 'word'));
    }

    public function testEachLaidOutWordCarriesItsSizeAndItsRung(): void
    {
        $laid = $this->weighting()->layout([['word' => 'pare-feu', 'count' => 6]], WordCloudScale::ProjectionFull);

        self::assertSame([['word' => 'pare-feu', 'count' => 6, 'size' => 92, 'step' => 'top']], $laid);
    }

    public function testAnEmptyCloudLaysOutToNothing(): void
    {
        self::assertSame([], $this->weighting()->layout([], WordCloudScale::ProjectionFull));
    }

    private function weighting(): WordCloudWeighting
    {
        return new WordCloudWeighting();
    }
}
