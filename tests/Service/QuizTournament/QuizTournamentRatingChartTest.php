<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentRatingChart;
use PHPUnit\Framework\TestCase;

/**
 * The geometry of « Ta cote, duel après duel » (H-mes-duels), on primitives: one point per rating,
 * the start on the left edge, the last on the right, round gridlines that frame every point.
 */
class QuizTournamentRatingChartTest extends TestCase
{
    public function testThePointsSpanThePlotFromLeftToRight(): void
    {
        $chart = QuizTournamentRatingChart::of([1000, 1012, 990, 1031]);

        self::assertCount(4, $chart['points']);
        self::assertSame(QuizTournamentRatingChart::LEFT, $chart['points'][0]['x']);
        self::assertSame(QuizTournamentRatingChart::RIGHT, $chart['points'][3]['x']);
        self::assertSame(1031, $chart['points'][3]['rating']);
        self::assertSame(3, $chart['points'][3]['duel']);
    }

    public function testHigherRatingsAreDrawnHigher(): void
    {
        $chart = QuizTournamentRatingChart::of([1000, 1012, 990, 1031]);

        self::assertLessThan($chart['points'][0]['y'], $chart['points'][3]['y']);
        self::assertGreaterThan($chart['points'][0]['y'], $chart['points'][2]['y']);
    }

    public function testTheGridIsRoundAndFramesEveryPoint(): void
    {
        $chart = QuizTournamentRatingChart::of([1000, 1012, 990, 1031]);
        $values = array_column($chart['grid'], 'value');

        self::assertSame([975, 1000, 1025, 1050], $values);
        foreach ($chart['points'] as $point) {
            self::assertGreaterThanOrEqual(QuizTournamentRatingChart::TOP, $point['y']);
            self::assertLessThanOrEqual(QuizTournamentRatingChart::BOTTOM, $point['y']);
        }
    }

    public function testASingleRatingIsStillAChart(): void
    {
        $chart = QuizTournamentRatingChart::of([1000]);

        self::assertCount(1, $chart['points']);
        self::assertSame(QuizTournamentRatingChart::LEFT, $chart['points'][0]['x']);
        self::assertNotEmpty($chart['grid']);
        self::assertSame('40,'.$chart['points'][0]['y'], $chart['polyline']);
    }

    public function testAWideRangeTakesAWiderStep(): void
    {
        $values = array_column(QuizTournamentRatingChart::of([1000, 1180, 860])['grid'], 'value');

        self::assertSame([850, 900, 950, 1000, 1050, 1100, 1150, 1200], $values);
    }
}
