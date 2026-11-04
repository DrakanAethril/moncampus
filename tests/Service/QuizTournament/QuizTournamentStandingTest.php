<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Enum\QuizTournamentStandingBlock;
use App\Service\QuizTournament\QuizTournamentStanding;
use App\Service\QuizTournament\QuizTournamentStandingEntry;
use App\Service\QuizTournament\QuizTournamentStandingRow;
use PHPUnit\Framework\TestCase;

/**
 * Being ranked (design/validated/quiz-tournament.md §6.2), on primitives: placement under five
 * duels, activity of three duels over fourteen days, the ladder rule, and the four blocks with their
 * orders.
 */
class QuizTournamentStandingTest extends TestCase
{
    public function testAPlayerWhoHoldsTheThreeConditionsIsRanked(): void
    {
        $standings = QuizTournamentStanding::compute([$this->entry(1, 'Alice', 1050, duels: 5, recent: 3, ladder: true)]);

        $row = $standings->rowFor(1);
        self::assertNotNull($row);
        self::assertSame(QuizTournamentStandingBlock::Ranked, $row->block);
        self::assertSame(1, $row->rank);
    }

    public function testUnderFiveDuelsAPlayerIsInPlacement(): void
    {
        $row = QuizTournamentStanding::compute([$this->entry(1, 'Alice', 1100, duels: 4, recent: 4, ladder: true)])->rowFor(1);
        self::assertNotNull($row);

        self::assertSame(QuizTournamentStandingBlock::Placement, $row->block);
        self::assertNull($row->rank);
    }

    public function testFewerThanThreeRecentDuelsMakeAPlayerInactive(): void
    {
        $row = QuizTournamentStanding::compute([$this->entry(1, 'Alice', 1100, duels: 12, recent: 2, ladder: true)])->rowFor(1);
        self::assertNotNull($row);

        self::assertSame(QuizTournamentStandingBlock::Inactive, $row->block);
        self::assertSame(1, $row->missingRecentDuels);
        self::assertFalse($row->missingLadder);
    }

    public function testTheLadderRuleUnheldMakesAPlayerInactive(): void
    {
        $row = QuizTournamentStanding::compute([$this->entry(1, 'Alice', 1100, duels: 12, recent: 6, ladder: false)])->rowFor(1);
        self::assertNotNull($row);

        self::assertSame(QuizTournamentStandingBlock::Inactive, $row->block);
        self::assertSame(0, $row->missingRecentDuels);
        self::assertTrue($row->missingLadder);
    }

    public function testAnEligibleStudentWithoutADuelHasNotPlayedYet(): void
    {
        $row = QuizTournamentStanding::compute([$this->entry(1, 'Alice', 1000, duels: 0)])->rowFor(1);
        self::assertNotNull($row);

        self::assertSame(QuizTournamentStandingBlock::NotPlayed, $row->block);
    }

    public function testAPlayerWhoLostEligibilityKeepsTheirLineButNotAnEmptyOne(): void
    {
        $standings = QuizTournamentStanding::compute([
            $this->entry(1, 'Alice', 1100, duels: 12, recent: 0, eligible: false),
            $this->entry(2, 'Bruno', 1000, duels: 0, eligible: false),
        ]);

        self::assertSame(QuizTournamentStandingBlock::Inactive, $standings->rowFor(1)?->block);
        self::assertNull($standings->rowFor(2));
    }

    public function testEachBlockHasItsOwnOrder(): void
    {
        $standings = QuizTournamentStanding::compute([
            $this->entry(1, 'Zoé Martin', 1000, duels: 0),
            $this->entry(2, 'Adam Petit', 1000, duels: 0),
            $this->entry(3, 'Hugo Lambert', 1080, duels: 3),
            $this->entry(4, 'Inès Roux', 1020, duels: 4),
            $this->entry(5, 'Léa Morel', 1060, duels: 4),
            $this->entry(6, 'Enzo Moreau', 990, duels: 9, recent: 1),
            $this->entry(7, 'Sarah Benali', 1150, duels: 9, recent: 0),
            $this->entry(8, 'Nathan Girard', 1064, duels: 8, recent: 3, ladder: true),
            $this->entry(9, 'Maëlys Petit', 1097, duels: 6, recent: 4, ladder: true),
        ]);

        self::assertSame([9, 8], $this->ids($standings->rows(QuizTournamentStandingBlock::Ranked)), 'rating, highest first');
        self::assertSame([5, 4, 3], $this->ids($standings->rows(QuizTournamentStandingBlock::Placement)), 'duels played, then rating');
        self::assertSame([7, 6], $this->ids($standings->rows(QuizTournamentStandingBlock::Inactive)), 'rating');
        self::assertSame([2, 1], $this->ids($standings->rows(QuizTournamentStandingBlock::NotPlayed)), 'name');
        self::assertSame([9, 8, 5, 4, 3, 7, 6, 2, 1], $this->ids($standings->all()), 'the four blocks, in their order');
    }

    public function testEqualRatingsShareARank(): void
    {
        $standings = QuizTournamentStanding::compute([
            $this->entry(1, 'Alice', 1100, duels: 6, recent: 3, ladder: true),
            $this->entry(2, 'Bruno', 1080, duels: 6, recent: 3, ladder: true),
            $this->entry(3, 'Chloé', 1080, duels: 7, recent: 3, ladder: true),
            $this->entry(4, 'Denis', 1050, duels: 6, recent: 3, ladder: true),
        ]);

        self::assertSame([1, 2, 2, 4], array_map(static fn (QuizTournamentStandingRow $row): ?int => $row->rank, $standings->rows(QuizTournamentStandingBlock::Ranked)));
        self::assertSame([1, 3, 2, 4], $this->ids($standings->rows(QuizTournamentStandingBlock::Ranked)), 'more duels first among equals');
    }

    public function testTheNearestRankedPlayersAreTheFiveClosestRatings(): void
    {
        $ranked = [
            ['id' => 1, 'rating' => 1300],
            ['id' => 2, 'rating' => 1210],
            ['id' => 3, 'rating' => 1190],
            ['id' => 4, 'rating' => 1100],
            ['id' => 5, 'rating' => 1000],
            ['id' => 6, 'rating' => 980],
            ['id' => 7, 'rating' => 1201],
            ['id' => 8, 'rating' => 600],
        ];

        // Equal distances: the higher rating first - the rule is about climbing.
        self::assertSame([7, 2, 3, 1, 4], QuizTournamentStanding::nearestRanked(99, 1200, $ranked));
        // The player themself is never one of their own neighbours.
        self::assertSame([2, 3, 1, 4, 5], QuizTournamentStanding::nearestRanked(7, 1201, $ranked));
    }

    public function testTheLadderOpponentIsOneOfTheFiveNearestRanked(): void
    {
        $ranked = [
            ['id' => 1, 'rating' => 1300],
            ['id' => 2, 'rating' => 1210],
            ['id' => 3, 'rating' => 1190],
            ['id' => 4, 'rating' => 1100],
            ['id' => 5, 'rating' => 1000],
            ['id' => 6, 'rating' => 980],
        ];

        self::assertTrue(QuizTournamentStanding::isLadderOpponent(99, 1200, 4, $ranked));
        self::assertFalse(QuizTournamentStanding::isLadderOpponent(99, 1200, 6, $ranked), 'the sixth nearest');
        self::assertFalse(QuizTournamentStanding::isLadderOpponent(99, 1200, 42, $ranked), 'an unranked opponent');
    }

    public function testWithNobodyElseRankedTheLadderRuleHoldsByItself(): void
    {
        // The first player to leave placement could otherwise never be ranked: the rule asks for a
        // ranked neighbour, and there is none yet.
        self::assertTrue(QuizTournamentStanding::isLadderOpponent(1, 1100, 42, []));
        self::assertTrue(QuizTournamentStanding::isLadderOpponent(1, 1100, 42, [['id' => 1, 'rating' => 1100]]));
    }

    private function entry(int $id, string $name, int $rating, int $duels, int $recent = 0, bool $ladder = false, bool $eligible = true): QuizTournamentStandingEntry
    {
        return new QuizTournamentStandingEntry($id, $name, $rating, $duels, $recent, $ladder, $eligible);
    }

    /**
     * @param list<QuizTournamentStandingRow> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (QuizTournamentStandingRow $row): int => $row->entry->userId, $rows);
    }
}
