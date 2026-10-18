<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Rules;

use App\Enum\SeriousGameBrightness;
use App\SeriousGame\Rules\AcquisitionRules;
use PHPUnit\Framework\TestCase;

/**
 * What a player acquires (design/validated/serious-games.md §1, §6.2), on primitives: a star lit
 * at 6/12 with the brightness of its best series, never lower; a comet when one error comes back
 * twice in the last 30 answers; « élucidée » at 2 out of 3; the game finished when everything is
 * lit. Nothing depends on time.
 */
class AcquisitionRulesTest extends TestCase
{
    public function testAStarLightsAtSixOutOfTwelve(): void
    {
        self::assertFalse(AcquisitionRules::isLit(null));
        self::assertFalse(AcquisitionRules::isLit(5));
        self::assertTrue(AcquisitionRules::isLit(6));
    }

    public function testTheBrightnessIsReadOnTheBestSeries(): void
    {
        self::assertNull(SeriousGameBrightness::fromBest(5));
        self::assertSame(SeriousGameBrightness::Discrete, SeriousGameBrightness::fromBest(6));
        self::assertSame(SeriousGameBrightness::Discrete, SeriousGameBrightness::fromBest(7));
        self::assertSame(SeriousGameBrightness::Clear, SeriousGameBrightness::fromBest(8));
        self::assertSame(SeriousGameBrightness::Clear, SeriousGameBrightness::fromBest(9));
        self::assertSame(SeriousGameBrightness::Bright, SeriousGameBrightness::fromBest(10));
        self::assertSame(SeriousGameBrightness::Bright, SeriousGameBrightness::fromBest(11));
        self::assertSame(SeriousGameBrightness::Blazing, SeriousGameBrightness::fromBest(12));
    }

    public function testTheBestNeverGoesDown(): void
    {
        self::assertSame(11, AcquisitionRules::best(11, 0));
        self::assertSame(12, AcquisitionRules::best(11, 12));
        self::assertSame(4, AcquisitionRules::best(null, 4));
    }

    public function testASeriesScoresItsRightAnswers(): void
    {
        self::assertSame(3, AcquisitionRules::score([true, false, true, true, false]));
        self::assertSame(0, AcquisitionRules::score([]));
    }

    public function testAnErrorMadeTwiceInTheLastThirtyAnswersRaisesAComet(): void
    {
        $answers = self::answers(['taux-arrival', null, 'frac-sign', 'taux-arrival']);

        self::assertSame(['taux-arrival'], AcquisitionRules::cometsToRaise($answers, [], []));
    }

    public function testOnlyTheLastThirtyAnswersCount(): void
    {
        $answers = self::answers(array_merge(['taux-arrival'], array_fill(0, 30, null), ['taux-arrival']));

        self::assertSame([], AcquisitionRules::cometsToRaise($answers, [], []));
    }

    public function testAnErrorAlreadyInViewRaisesNothing(): void
    {
        self::assertSame([], AcquisitionRules::cometsToRaise(self::answers(['frac-sign', 'frac-sign']), ['frac-sign'], []));
    }

    public function testAnswersCountedByASolvedCometAreNotCountedAgain(): void
    {
        $answers = [['misconception' => 'frac-sign', 'at' => 100], ['misconception' => 'frac-sign', 'at' => 110], ['misconception' => 'frac-sign', 'at' => 300]];

        self::assertSame([], AcquisitionRules::cometsToRaise($answers, [], ['frac-sign' => 200]));
        $answers[] = ['misconception' => 'frac-sign', 'at' => 310];
        self::assertSame(['frac-sign'], AcquisitionRules::cometsToRaise($answers, [], ['frac-sign' => 200]));
    }

    public function testDontKnowAndRightAnswersNameNoError(): void
    {
        self::assertSame([], AcquisitionRules::cometsToRaise(self::answers([null, null, null]), [], []));
    }

    public function testACometIsSolvedAtTwoOutOfThree(): void
    {
        self::assertTrue(AcquisitionRules::cometSolved([true, true, false]));
        self::assertTrue(AcquisitionRules::cometSolved([true, true, true]));
        self::assertFalse(AcquisitionRules::cometSolved([true, false, false]));
    }

    public function testTheGameIsFinishedWhenEveryUnitIsLit(): void
    {
        self::assertFalse(AcquisitionRules::finished(30, 31));
        self::assertTrue(AcquisitionRules::finished(31, 31));
        self::assertFalse(AcquisitionRules::finished(0, 0));
    }

    /**
     * @param list<string|null> $misconceptions oldest first
     *
     * @return list<array{misconception: string|null, at: int}>
     */
    private static function answers(array $misconceptions): array
    {
        return array_map(static fn (?string $m, int $i): array => ['misconception' => $m, 'at' => 1000 + $i], $misconceptions, array_keys($misconceptions));
    }
}
