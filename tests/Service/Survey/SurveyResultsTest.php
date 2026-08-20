<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Service\Survey\SurveyQuestionResult;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of the results, on primitives - written before the service, because this is one of
 * the two places in the feature where an error is **silent**: a wrong percentage raises nothing at
 * all, it simply displays (design/validated/surveys.md §14).
 *
 * Every case listed in §11 is here, and the two that are easiest to get wrong are spelled out:
 *
 *  - a multiple choice's percentages **sum above 100 %**, and that is correct;
 *  - a Commentaire enters **no** percentage but **does** enter the per-question response rate - a
 *    required comment left blank is a skipped question, like any other.
 */
class SurveyResultsTest extends TestCase
{
    /**
     * @param array<int, int> $counts   answer id => how many picked it
     * @param array<int, int> $ranks    answer id => summed rank (Ordre only)
     * @param array<int, int> $orderIndexes answer id => its order_index, which on a scale IS its value
     */
    private function questionResult(
        string $type,
        int $answered,
        int $targeted,
        array $counts,
        array $orderIndexes = [],
        array $ranks = [],
        bool $isScale = false,
    ): SurveyQuestionResult {
        return new SurveyQuestionResult(
            questionId: 1,
            type: $type,
            label: 'Une question',
            isScale: $isScale,
            answered: $answered,
            targeted: $targeted,
            counts: $counts,
            orderIndexes: $orderIndexes,
            rankSums: $ranks,
        );
    }

    public function testSingleChoicePercentagesSumToOneHundred(): void
    {
        $result = $this->questionResult('unique', answered: 24, targeted: 24, counts: [1 => 9, 2 => 8, 3 => 5, 4 => 2, 5 => 0]);

        self::assertSame(38.0, round($result->percentFor(1), 0));
        self::assertSame(33.0, round($result->percentFor(2), 0));
        self::assertSame(21.0, round($result->percentFor(3), 0));
        self::assertSame(8.0, round($result->percentFor(4), 0));
        self::assertSame(0.0, $result->percentFor(5));
        self::assertSame(100.0, round(array_sum(array_map($result->percentFor(...), [1, 2, 3, 4, 5])), 0));
    }

    /**
     * And the one case where they do not, which the screen has to say out loud: without the
     * mention, a reader concludes the arithmetic is broken.
     */
    public function testMultipleChoicePercentagesGoAboveOneHundred(): void
    {
        $result = $this->questionResult('multiple', answered: 24, targeted: 24, counts: [1 => 21, 2 => 11, 3 => 6, 4 => 3, 5 => 1, 6 => 2]);

        self::assertGreaterThan(100.0, array_sum(array_map($result->percentFor(...), [1, 2, 3, 4, 5, 6])));
        self::assertTrue($result->sumsAboveOneHundred());
        self::assertFalse($this->questionResult('unique', 24, 24, [1 => 24])->sumsAboveOneHundred());
    }

    /** A question nobody answered shows zeros, never a division by zero. */
    public function testAQuestionWithNoAnswerAtAll(): void
    {
        $result = $this->questionResult('unique', answered: 0, targeted: 24, counts: [1 => 0, 2 => 0]);

        self::assertSame(0.0, $result->percentFor(1));
        self::assertSame(0.0, $result->responseRate());
        self::assertNull($result->scaleAverage());
    }

    /**
     * The denominator is the number of answers *to this question*, not the number of respondents:
     * a question 6 people skipped is read on the 18 who did answer it.
     */
    public function testPercentagesAreReadOnWhoAnsweredThatQuestion(): void
    {
        $result = $this->questionResult('unique', answered: 18, targeted: 24, counts: [1 => 9, 2 => 9]);

        self::assertSame(50.0, $result->percentFor(1));
        self::assertSame(75.0, round($result->responseRate(), 0), '18 answers on 24 people aimed at');
    }

    /**
     * The scale average - the single number two waves are compared on. It weighs each answer by its
     * order_index, 0 being the low pole, which is what the flag declares.
     */
    public function testTheScaleAverageWeighsAnswersByTheirRank(): void
    {
        // 9×0 + 8×1 + 5×2 + 2×3 + 0×4 = 24 over 24 answers = 1,00 on 4.
        $result = $this->questionResult(
            'unique',
            answered: 24,
            targeted: 24,
            counts: [1 => 9, 2 => 8, 3 => 5, 4 => 2, 5 => 0],
            orderIndexes: [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4],
            isScale: true,
        );

        self::assertSame(1.0, $result->scaleAverage());
        self::assertSame(4, $result->scaleMax());
    }

    /** Without the flag there is no average: an arbitrary list averaged would mean nothing. */
    public function testAPlainSingleChoiceHasNoAverage(): void
    {
        $result = $this->questionResult(
            'unique',
            answered: 10,
            targeted: 10,
            counts: [1 => 7, 2 => 3],
            orderIndexes: [1 => 0, 2 => 1],
            isScale: false,
        );

        self::assertNull($result->scaleAverage());
    }

    /**
     * A ranking has no percentage at all - an average rank reads, twelve position bars do not.
     */
    public function testARankingYieldsAverageRanksAndNoPercentage(): void
    {
        // The stored rank is 0-based (order_index), the displayed one is 1-based: 24 respondents,
        // item 1 summing 19 → 19/24 + 1 = 1,79 ; item 2 summing 38 → 2,58.
        $result = $this->questionResult('ordre', answered: 24, targeted: 24, counts: [1 => 24, 2 => 24], ranks: [1 => 19, 2 => 38]);

        self::assertFalse($result->hasPercentages());
        self::assertSame(1.8, round($result->averageRankFor(1), 1));
        self::assertSame(2.6, round($result->averageRankFor(2), 1));
        // The collective ranking is by average rank, 1 being the most urgent.
        self::assertSame([1, 2], $result->rankedAnswerIds());
    }

    /**
     * The case §11 names explicitly. A comment enters no percentage - and it does enter the
     * per-question response rate, because a required comment left blank is a skipped question.
     */
    public function testACommentEntersNoPercentageButDoesEnterTheResponseRate(): void
    {
        $result = $this->questionResult('commentaire', answered: 19, targeted: 35, counts: []);

        self::assertFalse($result->hasPercentages());
        self::assertNull($result->scaleAverage());
        self::assertSame(54.0, round($result->responseRate(), 0), '19 answers on 35 people aimed at');
        self::assertSame(16, $result->skipped(), '35 aimed at, 19 answered - 16 skipped it');
    }

    /** An intertitle is not a question and never reaches this class at all. */
    public function testAnIntertitleIsNeverAResult(): void
    {
        self::assertFalse(SurveyQuestionResult::isMeasurable('titre'));
        self::assertTrue(SurveyQuestionResult::isMeasurable('unique'));
        self::assertTrue(SurveyQuestionResult::isMeasurable('commentaire'));
    }

    /**
     * The threshold that protects an anonymous respondent: under 3 responses, the distribution is
     * not shown at all. On a target of four people, a two-bar histogram points at somebody.
     */
    public function testTheAnonymousThresholdHidesTheDistributionButNeverTheRate(): void
    {
        $two = $this->questionResult('unique', answered: 2, targeted: 24, counts: [1 => 1, 2 => 1]);
        $three = $this->questionResult('unique', answered: 3, targeted: 24, counts: [1 => 2, 2 => 1]);

        self::assertFalse($two->isDisclosable(anonymous: true));
        self::assertTrue($three->isDisclosable(anonymous: true));
        // A nominative campaign has no threshold at all.
        self::assertTrue($two->isDisclosable(anonymous: false));
    }
}
