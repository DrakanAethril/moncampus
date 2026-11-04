<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentRatingTrend;
use PHPUnit\Framework\TestCase;

/**
 * « L'écart de cote sur 14 jours » of a standings line (design/validated/quiz-tournament.md §6.2):
 * the sum of what the finished duels of the window moved, per player.
 */
class QuizTournamentRatingTrendTest extends TestCase
{
    public function testEachPlayerSumsTheMovesOfTheirDuels(): void
    {
        $deltas = QuizTournamentRatingTrend::deltas([
            ['a' => 1, 'b' => 2, 'beforeA' => 1000, 'afterA' => 1020, 'beforeB' => 1000, 'afterB' => 980],
            ['a' => 3, 'b' => 1, 'beforeA' => 1000, 'afterA' => 1019, 'beforeB' => 1020, 'afterB' => 1001],
        ]);

        self::assertSame([1 => 1, 2 => -20, 3 => 19], $deltas);
    }

    public function testADuelWithoutItsRatingsAfterMovesNothing(): void
    {
        $deltas = QuizTournamentRatingTrend::deltas([
            ['a' => 1, 'b' => 2, 'beforeA' => 1000, 'afterA' => null, 'beforeB' => 1000, 'afterB' => null],
        ]);

        self::assertSame([1 => 0, 2 => 0], $deltas);
    }

    public function testNoDuelNoDelta(): void
    {
        self::assertSame([], QuizTournamentRatingTrend::deltas([]));
    }
}
