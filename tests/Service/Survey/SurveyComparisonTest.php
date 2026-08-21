<?php

declare(strict_types=1);

namespace App\Tests\Service\Survey;

use App\Service\Survey\SurveyComparisonRefusal;
use App\Service\Survey\SurveyWaveAlignment;
use PHPUnit\Framework\TestCase;

/**
 * How two waves of a series are put side by side - on primitives, and written before the service:
 * this is the second of the two places where a mistake is **silent** (surveys.md §14).
 *
 * The alignment is by comparison_key and by nothing else. A replay copies the snapshot word for
 * word, so the keys are equal *by construction* - which is why this class is really about the one
 * abnormal case: a still-draft wave edited before opening. That question is then declared « non
 * comparable » and excluded from the deltas, **while every other question keeps aligning**. A
 * comparison that keeps quiet about one question is worth more than one that aligns two different
 * questions.
 */
class SurveyComparisonTest extends TestCase
{
    public function testTwoIdenticalWavesAlignQuestionForQuestion(): void
    {
        $alignment = SurveyWaveAlignment::align([
            1 => ['k-rythme' => 'unique', 'k-outils' => 'multiple'],
            2 => ['k-rythme' => 'unique', 'k-outils' => 'multiple'],
        ]);

        self::assertSame(['k-rythme', 'k-outils'], $alignment->comparableKeys());
        self::assertSame([], $alignment->incomparableKeys());
    }

    /**
     * The abnormal case. The question whose shape changed is marked, and the others keep aligning -
     * it is not the whole comparison that is thrown away.
     */
    public function testAQuestionEditedBetweenWavesIsDeclaredIncomparable(): void
    {
        $alignment = SurveyWaveAlignment::align([
            1 => ['k-rythme' => 'unique', 'k-tuteur-v1' => 'unique'],
            2 => ['k-rythme' => 'unique', 'k-tuteur-v2' => 'unique'],
        ]);

        self::assertSame(['k-rythme'], $alignment->comparableKeys(), 'the untouched question still aligns');
        self::assertSame(['k-tuteur-v1', 'k-tuteur-v2'], $alignment->incomparableKeys());
    }

    /** A question absent from one wave is not comparable either - there is nothing to align it to. */
    public function testAQuestionMissingFromOneWaveIsNotAligned(): void
    {
        $alignment = SurveyWaveAlignment::align([
            1 => ['k-a' => 'unique', 'k-b' => 'unique'],
            2 => ['k-a' => 'unique'],
        ]);

        self::assertSame(['k-a'], $alignment->comparableKeys());
        self::assertSame(['k-b'], $alignment->incomparableKeys());
    }

    /** A series with a single wave has nothing to compare, and says so rather than drawing one bar. */
    public function testASingleWaveSeriesComparesNothing(): void
    {
        $alignment = SurveyWaveAlignment::align([1 => ['k-a' => 'unique']]);

        self::assertSame([], $alignment->comparableKeys());
        self::assertFalse($alignment->hasSomethingToCompare());
    }

    /**
     * A Commentaire is never aligned: two lists of verbatims put side by side do not subtract. They
     * are shown wave by wave, never against each other (§7.14).
     */
    public function testACommentIsNeverAligned(): void
    {
        $alignment = SurveyWaveAlignment::align([
            1 => ['k-note' => 'commentaire', 'k-rythme' => 'unique'],
            2 => ['k-note' => 'commentaire', 'k-rythme' => 'unique'],
        ]);

        self::assertSame(['k-rythme'], $alignment->comparableKeys());
        self::assertNotContains('k-note', $alignment->incomparableKeys(), 'not "incomparable" either - simply out of the comparison');
    }

    /** And so is an intertitle, which is not a question at all. */
    public function testAnIntertitleIsOutOfTheComparison(): void
    {
        $alignment = SurveyWaveAlignment::align([
            1 => ['k-section' => 'titre', 'k-rythme' => 'unique'],
            2 => ['k-section' => 'titre', 'k-rythme' => 'unique'],
        ]);

        self::assertSame(['k-rythme'], $alignment->comparableKeys());
    }

    /** The delta between two waves, in points - what the screen prints beside each bar. */
    public function testTheDeltaIsExpressedInPoints(): void
    {
        self::assertSame(8.0, round(SurveyWaveAlignment::delta(25.0, 33.0), 1));
        self::assertSame(-4.0, round(SurveyWaveAlignment::delta(29.0, 25.0), 1));
        self::assertNull(SurveyWaveAlignment::delta(null, 25.0), 'the first wave has no delta');
    }

    /**
     * Beyond four waves the screen stops stacking bars and switches to a curve. The limit lives in
     * the service, so it is a decision and not something discovered on screen (§9).
     */
    public function testBeyondFourWavesTheReadingSwitchesToACurve(): void
    {
        self::assertFalse(SurveyWaveAlignment::needsCurve(2));
        self::assertFalse(SurveyWaveAlignment::needsCurve(4));
        self::assertTrue(SurveyWaveAlignment::needsCurve(5));
    }

    /**
     * The individual comparison **refuses**, it does not hide (§7.15). If either wave is anonymous
     * the screen says why it can show nothing - including to an administrator, because there is no
     * name stored to show, not a permission to lift.
     */
    public function testTheIndividualComparisonRefusesWhenEitherWaveIsAnonymous(): void
    {
        self::assertSame(
            SurveyComparisonRefusal::AnonymousWave,
            SurveyWaveAlignment::individualRefusal(firstAnonymous: true, secondAnonymous: false),
        );
        self::assertSame(
            SurveyComparisonRefusal::AnonymousWave,
            SurveyWaveAlignment::individualRefusal(firstAnonymous: false, secondAnonymous: true),
        );
        self::assertSame(
            SurveyComparisonRefusal::AnonymousWave,
            SurveyWaveAlignment::individualRefusal(firstAnonymous: true, secondAnonymous: true),
        );
        self::assertNull(SurveyWaveAlignment::individualRefusal(firstAnonymous: false, secondAnonymous: false));
    }

    /**
     * And only the people present in the target of **both** waves are listed: a student who arrived
     * mid-year has no September column, and a half-empty row reads as a regression.
     */
    public function testOnlyPeopleInBothTargetsAreListed(): void
    {
        $shared = SurveyWaveAlignment::sharedTarget([10, 11, 12, 13], [11, 12, 14]);

        self::assertSame([11, 12], $shared);
    }

    public function testSharedTargetIsEmptyWhenNobodyOverlaps(): void
    {
        self::assertSame([], SurveyWaveAlignment::sharedTarget([1, 2], [3, 4]));
    }
}
