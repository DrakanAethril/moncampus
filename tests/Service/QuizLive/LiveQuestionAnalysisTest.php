<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizLive;

use App\Service\QuizLive\LiveQuestionAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * The analysis of a finished live contest, on primitives: a question's success rate, the order the
 * questions are read in, the bars of a vote. Reading them off a real contest is
 * App\Tests\Functional\QuizLivePublicTest.
 */
class LiveQuestionAnalysisTest extends TestCase
{
    public function testARateCountsThePlayersInTheRoomWhenTheQuestionClosed(): void
    {
        self::assertSame(50, LiveQuestionAnalysis::rate(2, 4));
        self::assertSame(33, LiveQuestionAnalysis::rate(1, 3));
        self::assertSame(0, LiveQuestionAnalysis::rate(0, 3), 'nobody right is 0 %, not « no figure »');
        self::assertNull(LiveQuestionAnalysis::rate(0, 0), 'a question the contest never reached has no rate');
    }

    public function testQuestionsAreReadWeakestFirstAndTheUnplayedOnesLast(): void
    {
        $rows = [
            ['number' => 1, 'rate' => 80],
            ['number' => 2, 'rate' => null],
            ['number' => 3, 'rate' => 20],
            ['number' => 4, 'rate' => 80],
        ];

        self::assertSame([3, 1, 4, 2], array_column(LiveQuestionAnalysis::sort($rows, 'rate_asc'), 'number'));
        self::assertSame([1, 4, 3, 2], array_column(LiveQuestionAnalysis::sort($rows, 'rate_desc'), 'number'));
        self::assertSame([1, 2, 3, 4], array_column(LiveQuestionAnalysis::sort($rows, 'number'), 'number'));
        self::assertSame([3, 1, 4, 2], array_column(LiveQuestionAnalysis::sort($rows, 'nonsense'), 'number'), 'weakest first by default');
    }

    public function testAVoteHasABarPerPropositionInItsOrderThenTheSilentOnes(): void
    {
        $bars = LiveQuestionAnalysis::bars(
            [['id' => 7, 'label' => 'Oui'], ['id' => 8, 'label' => 'Non'], ['id' => 9, 'label' => 'Je ne sais pas']],
            [[8], [7], [8], null],
        );

        self::assertSame(['Oui', 'Non', 'Je ne sais pas', null], array_column($bars, 'label'));
        self::assertSame([1, 2, 0, 1], array_column($bars, 'count'));
        self::assertSame([25, 50, 0, 25], array_column($bars, 'percent'), 'out of the players in the room, silent ones included');
        self::assertSame([false, false, false, true], array_column($bars, 'none'));
    }

    public function testAVoteNobodyWasAskedHasEmptyBars(): void
    {
        $bars = LiveQuestionAnalysis::bars([['id' => 7, 'label' => 'Oui']], []);

        self::assertSame([0, 0], array_column($bars, 'count'));
        self::assertSame([0, 0], array_column($bars, 'percent'));
    }
}
