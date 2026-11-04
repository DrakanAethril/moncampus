<?php

declare(strict_types=1);

namespace App\Tests\Service\QuizTournament;

use App\Service\QuizTournament\QuizTournamentRoundEntrant;
use App\Service\QuizTournament\QuizTournamentRoundPairing;
use PHPUnit\Framework\TestCase;

/**
 * « Clore et apparier » (design/validated/quiz-tournament.md §8, point 3), on primitives: the
 * present players sorted by rating and paired neighbour with neighbour, a pair already at three
 * encounters today avoided when another pairing exists, and with an odd count one exempt - the least
 * often exempted, then the lowest rated.
 */
class QuizTournamentRoundPairingTest extends TestCase
{
    public function testNeighboursByRatingArePairedTogether(): void
    {
        $result = QuizTournamentRoundPairing::pair([
            $this->entrant(1, 1000),
            $this->entrant(2, 1200),
            $this->entrant(3, 1100),
            $this->entrant(4, 900),
        ], []);

        self::assertSame([[2, 3], [1, 4]], $result->pairs);
        self::assertNull($result->exemptId);
    }

    public function testWithAnOddCountTheLeastExemptedThenTheLowestRatedSitsOut(): void
    {
        $result = QuizTournamentRoundPairing::pair([
            $this->entrant(1, 1000, exemptions: 1),
            $this->entrant(2, 1200),
            $this->entrant(3, 950, exemptions: 1),
            $this->entrant(4, 1100),
            $this->entrant(5, 1050),
        ], []);

        self::assertSame(5, $result->exemptId, 'never exempted, lowest of those');
        self::assertSame([[2, 4], [1, 3]], $result->pairs);
    }

    public function testAPairAtTheDailyCapIsAvoidedWhenPossible(): void
    {
        $result = QuizTournamentRoundPairing::pair([
            $this->entrant(1, 1200),
            $this->entrant(2, 1100),
            $this->entrant(3, 1000),
            $this->entrant(4, 900),
        ], [[2, 1]]);

        self::assertSame([[1, 3], [2, 4]], $result->pairs);
    }

    public function testTheAvoidanceLooksPastTheFirstChoice(): void
    {
        // Greedy would pair 1-2 and leave 3-4, which is capped; 1-3 / 2-4 is also capped (2-4), so
        // the only clean pairing is 1-4 / 2-3.
        $result = QuizTournamentRoundPairing::pair([
            $this->entrant(1, 1200),
            $this->entrant(2, 1100),
            $this->entrant(3, 1000),
            $this->entrant(4, 900),
        ], [[3, 4], [2, 4]]);

        self::assertSame([[1, 4], [2, 3]], $result->pairs);
    }

    public function testWhenEveryPairingHitsTheCapTheNeighboursPlayAnyway(): void
    {
        $result = QuizTournamentRoundPairing::pair([
            $this->entrant(1, 1200),
            $this->entrant(2, 1100),
        ], [[1, 2]]);

        self::assertSame([[1, 2]], $result->pairs);
    }

    public function testAloneOrNobody(): void
    {
        $alone = QuizTournamentRoundPairing::pair([$this->entrant(7, 1000)], []);

        self::assertSame([], $alone->pairs);
        self::assertSame(7, $alone->exemptId);

        $nobody = QuizTournamentRoundPairing::pair([], []);
        self::assertSame([], $nobody->pairs);
        self::assertNull($nobody->exemptId);
    }

    public function testALargeClassIsPairedQuickly(): void
    {
        $entrants = [];
        for ($id = 1; $id <= 35; ++$id) {
            $entrants[] = $this->entrant($id, 1000 + 7 * $id % 300);
        }
        // Every pair of neighbours in id order is capped: the search must not explode.
        $capped = [];
        for ($id = 1; $id < 35; ++$id) {
            $capped[] = [$id, $id + 1];
        }

        $result = QuizTournamentRoundPairing::pair($entrants, $capped);

        self::assertCount(17, $result->pairs);
        self::assertNotNull($result->exemptId);
        $seen = array_merge(...$result->pairs);
        self::assertCount(34, array_unique($seen));
        self::assertNotContains($result->exemptId, $seen);
    }

    private function entrant(int $id, int $rating, int $exemptions = 0): QuizTournamentRoundEntrant
    {
        return new QuizTournamentRoundEntrant($id, $rating, $exemptions);
    }
}
