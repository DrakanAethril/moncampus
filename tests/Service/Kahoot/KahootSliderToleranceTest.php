<?php

declare(strict_types=1);

namespace App\Tests\Service\Kahoot;

use App\Service\Kahoot\KahootSliderTolerance;
use PHPUnit\Framework\TestCase;

/**
 * A Kahoot slider's margin, guessed from who scored: at least the farthest answer that scored,
 * narrower than the nearest that did not, a round number when one fits.
 */
class KahootSliderToleranceTest extends TestCase
{
    public function testTheMarginIsTheRoundNumberBetweenTheFarthestHitAndTheNearestMiss(): void
    {
        // The real « salaire net moyen » question: 2373 scored, 3173 (440 away) did not.
        $answers = [
            ['value' => 2733.0, 'scored' => true],
            ['value' => 2773.0, 'scored' => true],
            ['value' => 2373.0, 'scored' => true],
            ['value' => 3173.0, 'scored' => false],
            ['value' => 2173.0, 'scored' => false],
        ];

        self::assertSame(['tolerance' => 400.0, 'guessed' => true], KahootSliderTolerance::infer(2733.0, $answers));
    }

    public function testWithoutARoundNumberInTheBracketTheFarthestHitIsKept(): void
    {
        $answers = [['value' => 137.0, 'scored' => true], ['value' => 139.0, 'scored' => false]];

        self::assertSame(['tolerance' => 37.0, 'guessed' => true], KahootSliderTolerance::infer(100.0, $answers));
    }

    public function testOnlyExactAnswersScoredMeansNoMargin(): void
    {
        $answers = [['value' => 50.0, 'scored' => true], ['value' => 51.0, 'scored' => false]];

        self::assertSame(['tolerance' => 0.0, 'guessed' => true], KahootSliderTolerance::infer(50.0, $answers));
    }

    public function testNobodyAnsweringLeavesNothingToRead(): void
    {
        self::assertSame(['tolerance' => 0.0, 'guessed' => false], KahootSliderTolerance::infer(50.0, []));
    }
}
