<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentElo;
use App\Service\QuizTournament\QuizTournamentStandingsAssembler;
use PHPUnit\Framework\TestCase;

/**
 * Who stands in a tournament's standings and with which facts (design/validated/quiz-tournament.md
 * §2 and §6.2), on primitives: the players with their counters, the eligible students who have not
 * played yet, and a player who lost their eligibility keeping their line.
 */
class QuizTournamentStandingsAssemblerTest extends TestCase
{
    public function testPlayersAndEligibleStudentsMakeOneEntryEach(): void
    {
        $entries = QuizTournamentStandingsAssembler::entries(
            players: [
                1 => ['sortName' => 'Martin Alice', 'rating' => 1080, 'duels' => 6],
                2 => ['sortName' => 'Durand Bruno', 'rating' => 950, 'duels' => 2],
            ],
            eligible: [1 => 'Martin Alice', 3 => 'Petit Chloé'],
            recentDuels: [1 => 3, 2 => 1],
            ladderIds: [1],
        );

        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry->userId] = $entry;
        }

        self::assertCount(3, $entries);

        self::assertSame(1080, $byId[1]->rating);
        self::assertSame(6, $byId[1]->duels);
        self::assertSame(3, $byId[1]->recentDuels);
        self::assertTrue($byId[1]->ladderMet);
        self::assertTrue($byId[1]->eligible);

        // A player the targeting no longer reaches keeps their line, ineligible.
        self::assertFalse($byId[2]->eligible);
        self::assertSame(1, $byId[2]->recentDuels);
        self::assertFalse($byId[2]->ladderMet);

        // An eligible student without a row has not played: the starting rating, no duel.
        self::assertSame('Petit Chloé', $byId[3]->sortName);
        self::assertSame(QuizTournamentElo::START, $byId[3]->rating);
        self::assertSame(0, $byId[3]->duels);
        self::assertSame(0, $byId[3]->recentDuels);
        self::assertTrue($byId[3]->eligible);
    }

    public function testNobodyGivesNoEntry(): void
    {
        self::assertSame([], QuizTournamentStandingsAssembler::entries([], [], [], []));
    }

    public function testTheClassShownIsTheReachedOneElseTheLastShown(): void
    {
        self::assertSame(12, QuizTournamentStandingsAssembler::displayedProgramId(12, 7));
        self::assertSame(7, QuizTournamentStandingsAssembler::displayedProgramId(null, 7));
        self::assertNull(QuizTournamentStandingsAssembler::displayedProgramId(null, null));
    }
}
